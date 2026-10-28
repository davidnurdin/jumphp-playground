<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Publisher;
use App\Service\Slugs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The creators' side: their projects, a new game, an import, the MCP token for their Claude. */
#[Route('/studio')]
final class StudioController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projects,
        private CategoryRepository $categories,
        private Projects $service,
        private EntityManagerInterface $em,
        private string $appUrl,
    ) {
    }

    #[Route('', name: 'studio', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('studio/index.html.twig', ['projects' => $this->projects->ownedBy($user)]);
    }

    #[Route('/nouveau', name: 'studio_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, Slugs $slugs): Response
    {
        $form = ['title' => '', 'slug' => '', 'category' => '', 'description' => '', 'brief' => ''];
        if ($request->isMethod('POST')) {
            $form = array_map('trim', array_intersect_key($request->request->all(), $form)) + $form;
            if (!$this->isCsrfTokenValid('studio-new', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $category = $this->categories->findOneBy(['slug' => $form['category']]) ?? throw new ProjectException('Choisissez une catégorie.');
                if (mb_strlen($form['brief']) < 20) {
                    throw new ProjectException('Décrivez votre jeu en quelques phrases : c\'est ce que votre Claude lira.');
                }
                $project = $this->service->create($user, $form['title'], $form['slug'] ?: $slugs->available(Slugs::fromTitle($form['title'])),
                    $category, $form['description'], $form['brief']);
                $this->addFlash('success', 'Projet créé : le dépôt GitHub se prépare.');
                return $this->redirectToRoute('studio_project', ['slug' => $project->getSlug()]);
            } catch (ProjectException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        return $this->render('studio/new.html.twig', ['form' => $form, 'categories' => $this->categories->ordered()]);
    }

    #[Route('/importer', name: 'studio_import', methods: ['GET', 'POST'])]
    public function import(Request $request, #[CurrentUser] User $user): Response
    {
        $form = ['repository' => '', 'slug' => '', 'category' => ''];
        if ($request->isMethod('POST')) {
            $form = array_map('trim', array_intersect_key($request->request->all(), $form)) + $form;
            if (!$this->isCsrfTokenValid('studio-import', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $category = $this->categories->findOneBy(['slug' => $form['category']]) ?? throw new ProjectException('Choisissez une catégorie.');
                $project = $this->service->import($user, $form['repository'], $form['slug'], $category, $this->service->token($user));
                $this->addFlash('success', 'Dépôt importé : la publication est en cours.');
                return $this->redirectToRoute('studio_project', ['slug' => $project->getSlug()]);
            } catch (ProjectException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        return $this->render('studio/import.html.twig', ['form' => $form, 'categories' => $this->categories->ordered()]);
    }

    #[Route('/projet/{slug}', name: 'studio_project', methods: ['GET', 'POST'])]
    public function project(string $slug, Request $request, #[CurrentUser] User $user): Response
    {
        $project = $this->owned($slug, $user);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio-project-' . $project->getId(), (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $title = trim((string)$request->request->get('title'));
            if ($title !== '') {
                $project->setTitle(mb_substr($title, 0, 80));
            }
            $project->setDescription(trim((string)$request->request->get('description')));
            $project->setBrief(trim((string)$request->request->get('brief')));
            if ($category = $this->categories->findOneBy(['slug' => (string)$request->request->get('category')])) {
                $project->setCategory($category);
            }
            $project->setListed($project->isPublished() && $request->request->getBoolean('listed'));
            $this->em->flush();
            $this->addFlash('success', 'Projet enregistré.');
            return $this->redirectToRoute('studio_project', ['slug' => $slug]);
        }
        return $this->render('studio/project.html.twig', [
            'project' => $project,
            'categories' => $this->categories->ordered(),
            'mcp_url' => rtrim($this->appUrl, '/') . '/mcp',
        ]);
    }

    #[Route('/projet/{slug}/sync', name: 'studio_sync', methods: ['POST'])]
    public function sync(string $slug, Request $request, #[CurrentUser] User $user, Publisher $publisher): Response
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-project-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $result = $publisher->sync($project, true);
        match ($result->status) {
            'published' => $this->addFlash('success', 'Publié : commit ' . substr((string)$result->sha, 0, 7) . '.'),
            'unchanged' => $this->addFlash('success', 'Déjà à jour.'),
            default => $this->addFlash('error', "Non publié :\n" . implode("\n", $result->errors)),
        };
        return $this->redirectToRoute('studio_project', ['slug' => $slug]);
    }

    #[Route('/projet/{slug}/retirer', name: 'studio_remove', methods: ['POST'])]
    public function remove(string $slug, Request $request, #[CurrentUser] User $user, Publisher $publisher): Response
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-remove-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $fs = new Filesystem();
        $fs->remove($publisher->repoDir($project));
        $fs->remove(dirname($publisher->repoDir($project), 2) . '/published/' . $project->getId());
        $this->em->remove($project);
        $this->em->flush();
        $this->addFlash('success', "« {$project->getTitle()} » est retiré du playground (le dépôt GitHub reste à vous).");
        return $this->redirectToRoute('studio');
    }

    #[Route('/mcp', name: 'studio_mcp', methods: ['GET', 'POST'])]
    public function mcp(Request $request, #[CurrentUser] User $user): Response
    {
        $token = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('studio-mcp', (string)$request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ($request->request->get('action') === 'revoke') {
                $user->setMcpToken(null, null);
                $this->addFlash('success', 'Jeton révoqué.');
            } else {
                $token = 'jph_' . rtrim(strtr(base64_encode(random_bytes(30)), '+/', 'Az'), '=');
                $user->setMcpToken(hash('sha256', $token), substr($token, 0, 8) . '…');
            }
            $this->em->flush();
        }
        return $this->render('studio/mcp.html.twig', [
            'token' => $token,
            'mcp_url' => rtrim($this->appUrl, '/') . '/mcp',
        ]);
    }

    private function owned(string $slug, User $user): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isOwnedBy($user)) {
            throw new NotFoundHttpException();
        }
        return $project;
    }
}
