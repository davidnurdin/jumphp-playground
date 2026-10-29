<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\SandboxShare;
use App\Entity\User;
use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Repository\SandboxShareRepository;
use App\Service\DraftAuthor;
use App\Service\DraftToken;
use App\Service\GameFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Sandbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The online editor's API: a game's files with the author's drafts on top (their session of the game), saved
 * as they type, played in the preview (the games origin, with a draft token), pushed to GitHub as one commit
 * or forked. Two kinds of authors, the same calls:
 *   /api/projects/<slug>/...      a signed-in user (the CSRF token of the page in X-CSRF-Token)
 *   /api/bac-a-sable/<slug>/...   a visitor of the sandbox, no account (Sandbox: their cookie; no push)
 */
#[Route('/api', condition: "request.getHost() != env('PLAY_HOST')")]
final class EditorApiController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projects,
        private DraftRepository $drafts,
        private GameFiles $files,
        private EntityManagerInterface $em,
        private Sandbox $sandbox,
        private TranslatorInterface $translator,
        private string $playUrl,
    ) {
    }

    #[Route('/projects/{slug}/tree', name: 'api_tree', methods: ['GET'])]
    #[Route('/bac-a-sable/{slug}/tree', name: 'sandbox_tree', methods: ['GET'])]
    public function tree(string $slug, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $files = [];
        foreach ($this->files->tree($project) as $path => $size) {
            $files[$path] = ['path' => $path, 'size' => $size, 'state' => 'published'];
        }
        foreach ($this->drafts->of($who, $project) as $path => $draft) {
            $files[$path] = ['path' => $path, 'size' => strlen((string)$draft->getContent()),
                'state' => $draft->isDeleted() ? 'deleted' : (isset($files[$path]) ? 'modified' : 'added')];
        }
        ksort($files, SORT_STRING);
        return new JsonResponse(['files' => array_values($files), 'commit' => $project->getPublishedSha()]);
    }

    #[Route('/projects/{slug}/file', name: 'api_file_get', methods: ['GET'])]
    #[Route('/bac-a-sable/{slug}/file', name: 'sandbox_file_get', methods: ['GET'])]
    public function get(string $slug, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $path = $this->path($request->query->get('path'));
        $draft = $this->drafts->forPath($who, $project, $path);
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

    #[Route('/projects/{slug}/file', name: 'api_file_put', methods: ['PUT'])]
    #[Route('/bac-a-sable/{slug}/file', name: 'sandbox_file_put', methods: ['PUT'])]
    public function put(string $slug, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $data = $request->toArray();
        $path = $this->path($data['path'] ?? null);
        $content = (string)($data['content'] ?? '');
        $max = $who->isVisitor() ? Sandbox::MAX_FILE : GameFiles::MAX_EDIT;
        if (strlen($content) > $max || !GameFiles::isText($path)) {
            return new JsonResponse(['error' => $this->translator->trans('Fichier texte de %max% Ko au plus.', ['%max%' => intdiv($max, 1024)])], 400);
        }
        $draft = $this->drafts->forPath($who, $project, $path);
        if (!$draft && $who->isVisitor() && count($this->drafts->of($who, $project)) >= Sandbox::MAX_FILES) {
            return new JsonResponse(['error' => $this->translator->trans('Le bac à sable garde %count% fichiers modifiés au plus.', ['%count%' => Sandbox::MAX_FILES])], 400);
        }
        $draft ??= $who->newDraft($project, $path);
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

    #[Route('/projects/{slug}/file', name: 'api_file_delete', methods: ['DELETE'])]
    #[Route('/bac-a-sable/{slug}/file', name: 'sandbox_file_delete', methods: ['DELETE'])]
    public function delete(string $slug, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $path = $this->path($request->query->get('path'));
        $draft = $this->drafts->forPath($who, $project, $path);
        if (!$this->files->path($project, $path)) {
            // a file only in the drafts: gone
            if ($draft) {
                $this->em->remove($draft);
            }
        } else {
            $draft ??= $who->newDraft($project, $path);
            $draft->markDeleted();
            $this->em->persist($draft);
        }
        $this->em->flush();
        return new JsonResponse(['deleted' => true]);
    }

    #[Route('/projects/{slug}/discard', name: 'api_discard', methods: ['POST'])]
    #[Route('/bac-a-sable/{slug}/discard', name: 'sandbox_discard', methods: ['POST'])]
    public function discard(string $slug, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $path = $request->toArray()['path'] ?? null;
        if ($path === null) {
            $this->drafts->clear($who, $project);
        } elseif ($draft = $this->drafts->forPath($who, $project, $this->path($path))) {
            $this->em->remove($draft);
            $this->em->flush();
        }
        return new JsonResponse(['discarded' => true]);
    }

    #[Route('/projects/{slug}/preview', name: 'api_preview', methods: ['GET'])]
    #[Route('/bac-a-sable/{slug}/preview', name: 'sandbox_preview', methods: ['GET'])]
    public function preview(string $slug, Request $request, #[CurrentUser] ?User $user, DraftToken $tokens): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $token = $tokens->make($project->getId(), $who);
        return new JsonResponse(['url' => rtrim($this->playUrl, '/') . "/$slug/play/?draft=" . rawurlencode($token) . '&t=' . time()]);
    }

    /** A link to this session of the game (SandboxShare): a frozen copy of its changes, kept for the chosen time. */
    #[Route('/projects/{slug}/share', name: 'api_share', methods: ['POST'])]
    #[Route('/bac-a-sable/{slug}/share', name: 'sandbox_share_make', methods: ['POST'])]
    public function share(string $slug, Request $request, #[CurrentUser] ?User $user, SandboxShareRepository $shares): JsonResponse
    {
        [$project, $who] = $this->open($slug, $request, $user);
        $data = $request->toArray();
        $duration = (string)($data['duration'] ?? '');
        if (!array_key_exists($duration, SandboxShare::DURATIONS)) {
            return new JsonResponse(['error' => 'duration'], 400);
        }
        if (!$this->drafts->of($who, $project)) {
            return new JsonResponse(['error' => $this->translator->trans('Rien à partager : changez d\'abord le jeu.')], 400);
        }
        if ($shares->countFrom($who->key(), new \DateTimeImmutable('-1 day')) >= 30) {
            return new JsonResponse(['error' => $this->translator->trans('Trop de liens aujourd\'hui : réessayez demain.')], 429);
        }
        $title = mb_substr(trim((string)($data['title'] ?? '')), 0, 80);
        $share = new SandboxShare($project, $who->key(), $duration, $title, $user);
        $this->em->persist($share);
        $this->em->flush();
        $this->drafts->copy($who, DraftAuthor::visitor($share->getVisitor()), $project);
        return new JsonResponse([
            'url' => $this->generateUrl('sandbox_shared', ['code' => $share->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
            'expires' => $share->getExpiresAt()?->format(\DATE_ATOM),
        ]);
    }

    #[Route('/projects/{slug}/push', name: 'api_push', methods: ['POST'])]
    public function push(string $slug, Request $request, #[CurrentUser] User $user, Projects $service): JsonResponse
    {
        [$project] = $this->open($slug, $request, $user);
        try {
            $sha = $service->push($user, $project, (string)($request->toArray()['message'] ?? ''));
        } catch (ProjectException $e) {
            return new JsonResponse(['error' => $e->trans($this->translator)], 409);
        }
        return new JsonResponse(['commit' => $sha, 'url' => $project->getRepoUrl() . '/commit/' . $sha]);
    }

    /** @return array{0: Project, 1: DraftAuthor} the game and whose drafts, after the checks of their kind */
    private function open(string $slug, Request $request, ?User $user): array
    {
        $token = (string)$request->headers->get('X-CSRF-Token');
        if (str_starts_with((string)$request->attributes->get('_route'), 'sandbox_')) {
            $visitor = $this->sandbox->visitor($request);
            if (!$visitor || !hash_equals($this->sandbox->token($visitor), $token)) {
                throw new AccessDeniedHttpException('CSRF');
            }
            $who = DraftAuthor::visitor($visitor);
        } else {
            if (!$user || !$this->isCsrfTokenValid('editor', $token)) {
                throw new AccessDeniedHttpException('CSRF');
            }
            $who = DraftAuthor::user($user);
        }
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        return [$project, $who];
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
