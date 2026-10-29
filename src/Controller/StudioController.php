<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\TestRun;
use App\Entity\User;
use App\Message\ShootProject;
use App\Repository\CategoryRepository;
use App\Repository\CommentRepository;
use App\Repository\DraftRepository;
use App\Repository\NoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\SandboxShareRepository;
use App\Repository\TestRunRepository;
use App\Repository\TodoRepository;
use App\Service\PrivateFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Publisher;
use App\Service\Slugs;
use App\Service\TestRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The creators' side: their projects, a new game, an import, the MCP token for their Claude. */
#[Route('/studio')]
final class StudioController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projects,
        private CategoryRepository $categories,
        private Projects $service,
        private EntityManagerInterface $em,
        private PrivateFiles $private,
        private TranslatorInterface $translator,
        private string $appUrl,
    ) {
    }

    #[Route('', name: 'studio', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, CommentRepository $comments, DraftRepository $drafts, SandboxShareRepository $shares): Response
    {
        $counts = $drafts->countsOf($user);
        return $this->render('studio/index.html.twig', [
            'projects' => $this->projects->ownedByPage($user, $request->query->getInt('page', 1)),
            'pending' => $comments->pendingByProject($user),
            // the games they changed in the online editor (or imported from the sandbox), not pushed yet
            'drafts' => array_map(fn ($p) => ['project' => $p, 'count' => $counts[$p->getId()]], $counts ? $this->projects->findBy(['id' => array_keys($counts)]) : []),
            'shares' => $shares->of($user),
        ]);
    }

    /** What others said on the user's games (comments, ideas, issues), the ones waiting for approval first to act on. */
    #[Route('/notifications', name: 'studio_notifications', methods: ['GET'])]
    public function notifications(Request $request, #[CurrentUser] User $user, CommentRepository $comments): Response
    {
        $isPendingOnly = $request->query->get('filtre') === 'attente';
        $seen = $user->getNotificationsSeenAt();
        $response = $this->render('studio/notifications.html.twig', [
            'messages' => $comments->notificationsOf($user, $isPendingOnly, $request->query->getInt('page', 1)),
            'pending' => array_sum($comments->pendingByProject($user)),
            'is_pending_only' => $isPendingOnly,
            'seen' => $seen,
        ]);
        $user->setNotificationsSeenAt(new \DateTimeImmutable());
        $this->em->flush();
        return $response;
    }

    #[Route('/nouveau', name: 'studio_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, Slugs $slugs): Response
    {
        $form = ['title' => '', 'slug' => '', 'kind' => 'original', 'category' => '', 'description' => '', 'brief' => ''];
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
                    $category, $form['description'], $form['brief'], $form['kind']);
                $this->addFlash('success', 'Projet créé : le dépôt GitHub se prépare.');
                return $this->redirectToRoute('studio_project', ['slug' => $project->getSlug()]);
            } catch (ProjectException $e) {
                $this->addFlash('error', $e->trans($this->translator));
            }
        }
        return $this->render('studio/new.html.twig', ['form' => $form, 'kinds' => Project::KINDS, 'categories' => $this->categories->ordered()]);
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
                $this->addFlash('error', $e->trans($this->translator));
            }
        }
        return $this->render('studio/import.html.twig', ['form' => $form, 'categories' => $this->categories->ordered()]);
    }

    #[Route('/projet/{slug}', name: 'studio_project', methods: ['GET', 'POST'])]
    public function project(string $slug, Request $request, #[CurrentUser] User $user, CommentRepository $comments, NoteRepository $notes, TodoRepository $todos, TestRunRepository $runs, TestRunner $tests): Response
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
            'private' => $this->private->index($project),
            'pending' => $comments->pendingByProject($user)[$project->getId()] ?? 0,
            'notes' => $notes->findBy(['project' => $project], ['name' => 'ASC']),
            'todos' => $todos->findBy(['project' => $project], ['done' => 'ASC', 'id' => 'ASC']),
            'test_runs' => array_map($tests->refresh(...), $runs->latest($project, 5)),
            'has_tests' => $tests->hasTests($project),
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
            'published' => $this->addFlash('success', $this->translator->trans('Publié : commit %sha%.', ['%sha%' => substr((string)$result->sha, 0, 7)])),
            'unchanged' => $this->addFlash('success', 'Déjà à jour.'),
            default => $this->addFlash('error', $this->translator->trans('Non publié :') . "\n" . implode("\n", $result->errors)),
        };
        return $this->redirectToRoute('studio_project', ['slug' => $slug]);
    }

    /** The game's PHPUnit tests, run now in the tester's sandbox (TestRunner). */
    #[Route('/projet/{slug}/tests', name: 'studio_tests_run', methods: ['POST'])]
    public function testsRun(string $slug, Request $request, #[CurrentUser] User $user, TestRunner $tests): Response
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-project-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $run = $tests->start($project);
        } catch (ProjectException $e) {
            $this->addFlash('error', $e->trans($this->translator));
            return $this->redirectToRoute('studio_project', ['slug' => $slug]);
        }
        return $this->redirectToRoute('studio_tests', ['slug' => $slug, 'id' => $run->getId()]);
    }

    #[Route('/projet/{slug}/tests/{id}', name: 'studio_tests', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function testsShow(string $slug, int $id, #[CurrentUser] User $user, TestRunRepository $runs, TestRunner $tests): Response
    {
        $run = $this->run($slug, $id, $user, $runs);
        $project = $run->getProject();
        $tests->refresh($run);
        return $this->render('studio/tests.html.twig', ['project' => $project, 'run' => $run, 'runs' => $runs->latest($project, 10), 'films' => $this->films($run, $tests)]);
    }

    /** A run as it goes (the tests page asks every second while it runs): done or not, the films so far. */
    #[Route('/projet/{slug}/tests/{id}/etat', name: 'studio_tests_state', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function testsState(string $slug, int $id, #[CurrentUser] User $user, TestRunRepository $runs, TestRunner $tests): JsonResponse
    {
        $run = $this->run($slug, $id, $user, $runs);
        $tests->refresh($run);
        return new JsonResponse(['done' => $run->isDone(), 'status' => $run->getStatus(), 'films' => $this->films($run, $tests)]);
    }

    /** A frame of a game test's film. */
    #[Route('/projet/{slug}/tests/{id}/film/{n}/{frame}.png', name: 'studio_tests_frame', requirements: ['id' => '\d+', 'n' => '\d{3}', 'frame' => '\d{4}'], methods: ['GET'])]
    public function testsFrame(string $slug, int $id, string $n, string $frame, #[CurrentUser] User $user, TestRunRepository $runs, TestRunner $tests): Response
    {
        $file = $tests->frame($this->run($slug, $id, $user, $runs), $n, $frame) ?? throw new NotFoundHttpException();
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPrivate();
        $response->setMaxAge(86400);
        return $response;
    }

    private function run(string $slug, int $id, User $user, TestRunRepository $runs): TestRun
    {
        $project = $this->owned($slug, $user);
        $run = $runs->find($id);
        if (!$run || $run->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }
        return $run;
    }

    /**
     * The filmed game tests of a run, with what PHPUnit said of each (run while it runs) and a readable name
     * ("testThePaddleGoesDown": "The paddle goes down").
     *
     * @return list<array{n: string, name: string, label: string, frames: int, status: string, message: ?string}>
     */
    private function films(TestRun $run, TestRunner $tests): array
    {
        $cases = [];
        foreach ((array)($run->getSummary()['cases'] ?? []) as $case) {
            $cases[$case['class'] . '::' . $case['name']] = $case;
        }
        $films = [];
        foreach ($tests->films($run) as $film) {
            $case = $cases[$film['name']] ?? null;
            $method = (string)preg_replace('/^.*::/', '', $film['name']);
            $words = trim((string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', (string)preg_replace('/^test_?/', '', $method)));
            $films[] = $film + [
                'label' => ucfirst(mb_strtolower(str_replace('_', ' ', $words))) ?: $method,
                'status' => $case['status'] ?? ($run->isDone() ? 'error' : 'run'),
                'message' => $case['message'] ?? null,
            ];
        }
        return $films;
    }

    /** One private file (assets/app.js sends them one by one): path = folder + name. */
    #[Route('/projet/{slug}/prives', name: 'studio_private_add', methods: ['POST'])]
    public function privateAdd(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-project-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('file');
        if (!$file || !$file->isValid()) {
            return new JsonResponse(['error' => $this->translator->trans('Fichier manquant ou trop gros (%max% Mo au plus).', ['%max%' => intdiv(PrivateFiles::MAX_FILE, 1048576)])], 400);
        }
        try {
            $path = $this->private->add($project, (string)$request->request->get('path'), $file->getPathname());
        } catch (ProjectException $e) {
            return new JsonResponse(['error' => $e->trans($this->translator)], 400);
        }
        return new JsonResponse(['path' => $path, 'size' => $file->getSize()]);
    }

    /** After a change of the private files: a new screenshot if the game's picture was one (it may have shown an error). */
    #[Route('/projet/{slug}/prives/fin', name: 'studio_private_done', methods: ['POST'])]
    public function privateDone(string $slug, Request $request, #[CurrentUser] User $user, MessageBusInterface $bus): Response
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-project-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        foreach ((array)$request->request->all('remove') as $path) {
            $this->private->remove($project, (string)$path);
        }
        if ($project->isPublished() && (!$project->hasThumbnail() || $project->isShotThumbnail())) {
            $bus->dispatch(new ShootProject($project->getId(), true));
        }
        $this->addFlash('success', 'Fichiers privés à jour : le jeu les charge dès maintenant.');
        return $this->redirectToRoute('studio_project', ['slug' => $slug]);
    }

    #[Route('/projet/{slug}/retirer', name: 'studio_remove', methods: ['POST'])]
    public function remove(string $slug, Request $request, #[CurrentUser] User $user, Publisher $publisher, PrivateFiles $private): Response
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-remove-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $fs = new Filesystem();
        $fs->remove($publisher->repoDir($project));
        $fs->remove(dirname($publisher->repoDir($project), 2) . '/published/' . $project->getId());
        $private->removeAll($project);
        $this->em->remove($project);
        $this->em->flush();
        $this->addFlash('success', $this->translator->trans('« %title% » est retiré du playground (le dépôt GitHub reste à vous).', ['%title%' => $project->getTitle()]));
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
