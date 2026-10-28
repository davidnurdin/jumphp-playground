<?php

namespace App\Controller;

use App\Entity\Draft;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Service\DraftToken;
use App\Service\GameFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The online editor's API: a game's files with the user's drafts on top (their session of the game), saved
 * as they type, played in the preview (the games origin, with a draft token), pushed to GitHub as one commit
 * or forked. Every call carries the CSRF token of the page (X-CSRF-Token).
 */
#[Route('/api/projects/{slug}', requirements: ['slug' => '[a-z][a-z0-9-]+'])]
final class EditorApiController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projects,
        private DraftRepository $drafts,
        private GameFiles $files,
        private EntityManagerInterface $em,
        private string $playUrl,
    ) {
    }

    #[Route('/tree', name: 'api_tree', methods: ['GET'])]
    public function tree(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->project($slug, $request);
        $files = [];
        foreach ($this->files->tree($project) as $path => $size) {
            $files[$path] = ['path' => $path, 'size' => $size, 'state' => 'published'];
        }
        foreach ($this->drafts->of($user, $project) as $path => $draft) {
            $files[$path] = ['path' => $path, 'size' => strlen((string)$draft->getContent()),
                'state' => $draft->isDeleted() ? 'deleted' : (isset($files[$path]) ? 'modified' : 'added')];
        }
        ksort($files, SORT_STRING);
        return new JsonResponse(['files' => array_values($files), 'commit' => $project->getPublishedSha()]);
    }

    #[Route('/file', name: 'api_file_get', methods: ['GET'])]
    public function get(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->project($slug, $request);
        $path = $this->path($request->query->get('path'));
        $draft = $this->drafts->forPath($user, $project, $path);
        if ($draft && !$draft->isDeleted()) {
            return new JsonResponse(['path' => $path, 'content' => $draft->getContent(), 'draft' => true, 'text' => true]);
        }
        $file = $this->files->path($project, $path) ?? throw new NotFoundHttpException();
        if (!GameFiles::isText($path) || filesize($file) > GameFiles::MAX_EDIT || !mb_check_encoding($content = (string)file_get_contents($file), 'UTF-8')) {
            return new JsonResponse(['path' => $path, 'text' => false, 'size' => filesize($file),
                'url' => rtrim($this->playUrl, '/') . "/$slug/raw/" . implode('/', array_map('rawurlencode', explode('/', $path)))]);
        }
        return new JsonResponse(['path' => $path, 'content' => $content, 'draft' => false, 'text' => true]);
    }

    #[Route('/file', name: 'api_file_put', methods: ['PUT'])]
    public function put(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->project($slug, $request);
        $data = $request->toArray();
        $path = $this->path($data['path'] ?? null);
        $content = (string)($data['content'] ?? '');
        if (strlen($content) > GameFiles::MAX_EDIT || !GameFiles::isText($path)) {
            return new JsonResponse(['error' => 'Fichier texte de 512 Ko au plus.'], 400);
        }
        $draft = $this->drafts->forPath($user, $project, $path) ?? new Draft($user, $project, $path);
        $published = $this->files->path($project, $path);
        if ($published && file_get_contents($published) === $content) {
            // back to the published version: no draft any more
            if ($draft->getId()) {
                $this->em->remove($draft);
                $this->em->flush();
            }
            return new JsonResponse(['saved' => true, 'state' => 'published']);
        }
        $draft->setContent($content);
        $this->em->persist($draft);
        $this->em->flush();
        return new JsonResponse(['saved' => true, 'state' => $published ? 'modified' : 'added']);
    }

    #[Route('/file', name: 'api_file_delete', methods: ['DELETE'])]
    public function delete(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->project($slug, $request);
        $path = $this->path($request->query->get('path'));
        $draft = $this->drafts->forPath($user, $project, $path);
        if (!$this->files->path($project, $path)) {
            // a file only in the drafts: gone
            if ($draft) {
                $this->em->remove($draft);
            }
        } else {
            $draft ??= new Draft($user, $project, $path);
            $draft->markDeleted();
            $this->em->persist($draft);
        }
        $this->em->flush();
        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/discard', name: 'api_discard', methods: ['POST'])]
    public function discard(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->project($slug, $request);
        $path = $request->toArray()['path'] ?? null;
        if ($path === null) {
            $this->drafts->clear($user, $project);
        } elseif ($draft = $this->drafts->forPath($user, $project, $this->path($path))) {
            $this->em->remove($draft);
            $this->em->flush();
        }
        return new JsonResponse(['discarded' => true]);
    }

    #[Route('/preview', name: 'api_preview', methods: ['GET'])]
    public function preview(string $slug, Request $request, #[CurrentUser] User $user, DraftToken $tokens): JsonResponse
    {
        $project = $this->project($slug, $request);
        $token = $tokens->make($project->getId(), $user->getId());
        return new JsonResponse(['url' => rtrim($this->playUrl, '/') . "/$slug/play/?draft=" . rawurlencode($token) . '&t=' . time()]);
    }

    #[Route('/push', name: 'api_push', methods: ['POST'])]
    public function push(string $slug, Request $request, #[CurrentUser] User $user, Projects $service): JsonResponse
    {
        $project = $this->project($slug, $request);
        try {
            $sha = $service->push($user, $project, (string)($request->toArray()['message'] ?? ''));
        } catch (ProjectException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }
        return new JsonResponse(['commit' => $sha, 'url' => $project->getRepoUrl() . '/commit/' . $sha]);
    }

    private function project(string $slug, Request $request): Project
    {
        if (!$this->isCsrfTokenValid('editor', (string)$request->headers->get('X-CSRF-Token'))) {
            throw new AccessDeniedHttpException('CSRF');
        }
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        return $project;
    }

    private function path(mixed $path): string
    {
        $clean = is_string($path) ? GameFiles::clean($path) : null;
        if ($clean === null || strlen($clean) > 255 || preg_match('#(^|/)\.git(/|$)#', $clean)) {
            throw new NotFoundHttpException('bad path');
        }
        return $clean;
    }
}
