<?php

namespace App\Controller;

use App\Entity\SandboxShare;
use App\Entity\User;
use App\Repository\DraftRepository;
use App\Repository\ProjectRepository;
use App\Repository\SandboxShareRepository;
use App\Service\DraftAuthor;
use App\Service\DraftToken;
use App\Service\Sandbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * The sandbox: try JumPHP without an account, from hello-world or any game (the online editor, no push). Its
 * session of a game can be shared by a link (SandboxShare: /bac-a-sable/partage/<code>): played by whoever opens
 * it, continued in their own sandbox, or imported into the space of a signed-in user (their drafts of the game,
 * in the online editor: from there they push it or fork the game).
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class SandboxController extends AbstractController
{
    use TargetPathTrait;

    private const SLUG = '[a-z][a-z0-9-]{1,38}[a-z0-9]';

    public function __construct(
        private ProjectRepository $projects,
        private DraftRepository $drafts,
        private SandboxShareRepository $shares,
        private Sandbox $sandbox,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/bac-a-sable', name: 'sandbox', methods: ['GET'])]
    #[Route('/sandbox', name: 'sandbox_en', methods: ['GET'])]
    public function start(): Response
    {
        return $this->redirectToRoute('sandbox_game', ['slug' => Sandbox::START]);
    }

    #[Route('/bac-a-sable/{slug}', name: 'sandbox_game', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function game(string $slug, Request $request): Response
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        $this->drafts->purgeVisitors(new \DateTimeImmutable('-' . Sandbox::PURGE_DAYS . ' days'));
        $this->shares->purge();
        $response = new Response();
        $visitor = $this->sandbox->makeVisitor($request, $response);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $this->render('game/edit.html.twig', [
            'project' => $project,
            'sandbox' => true,
            'api' => $this->generateUrl('sandbox_tree', ['slug' => $slug]),
            'token' => $this->sandbox->token($visitor),
            'can_push' => false,
            'drafts' => count($this->drafts->of(DraftAuthor::visitor($visitor), $project)),
            'fork_slug' => null,
        ], $response);
    }

    /** The sandbox's changes of a game into the signed-in user's space (their drafts: the online editor). */
    #[Route('/bac-a-sable/{slug}/importer', name: 'sandbox_import', requirements: ['slug' => self::SLUG], methods: ['POST'])]
    public function import(string $slug, Request $request, #[CurrentUser] ?User $user): Response
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        $visitor = $this->sandbox->visitor($request);
        if (!$project || !$visitor || !$this->isCsrfTokenValid('sandbox-import', (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$user) {
            return $this->signIn($request, $this->generateUrl('sandbox_game', ['slug' => $slug]));
        }
        $count = $this->drafts->copy(DraftAuthor::visitor($visitor), DraftAuthor::user($user), $project);
        $this->addFlash('success', $this->imported($count));
        return $this->redirectToRoute('game_edit', ['slug' => $slug]);
    }

    #[Route('/bac-a-sable/partage/{code}', name: 'sandbox_shared', requirements: ['code' => '[A-Za-z0-9]{10}'], methods: ['GET'])]
    #[Route('/sandbox/share/{code}', name: 'sandbox_shared_en', requirements: ['code' => '[A-Za-z0-9]{10}'], methods: ['GET'])]
    public function shared(string $code, DraftToken $tokens, string $playUrl): Response
    {
        $this->shares->purge();
        $share = $this->share($code);
        $share->viewed();
        $this->em->flush();
        $project = $share->getProject();
        $token = $tokens->make((int)$project->getId(), DraftAuthor::visitor($share->getVisitor()));
        $response = $this->render('sandbox/shared.html.twig', [
            'share' => $share,
            'project' => $project,
            'files' => array_map(fn ($d) => ['path' => $d->getPath(), 'deleted' => $d->isDeleted()], array_values($this->drafts->of(DraftAuthor::visitor($share->getVisitor()), $project))),
            'play' => rtrim($playUrl, '/') . '/' . $project->getSlug() . '/play/?draft=' . rawurlencode($token),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }

    /** A shared session into one's own sandbox (it replaces what was there for this game), or one's space. */
    #[Route('/bac-a-sable/partage/{code}/{to}', name: 'sandbox_shared_take', requirements: ['code' => '[A-Za-z0-9]{10}', 'to' => 'bac-a-sable|espace'], methods: ['POST'])]
    public function take(string $code, string $to, Request $request, #[CurrentUser] ?User $user): Response
    {
        $share = $this->share($code);
        if (!$this->isCsrfTokenValid('share-' . $share->getCode(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $project = $share->getProject();
        $from = DraftAuthor::visitor($share->getVisitor());
        if ($to === 'espace') {
            if (!$user) {
                return $this->signIn($request, $this->generateUrl('sandbox_shared', ['code' => $code]));
            }
            $this->addFlash('success', $this->imported($this->drafts->copy($from, DraftAuthor::user($user), $project)));
            return $this->redirectToRoute('game_edit', ['slug' => $project->getSlug()]);
        }
        $response = $this->redirectToRoute('sandbox_game', ['slug' => $project->getSlug()]);
        $this->drafts->copy($from, DraftAuthor::visitor($this->sandbox->makeVisitor($request, $response)), $project);
        return $response;
    }

    /** A link of the user's, removed (studio). */
    #[Route('/bac-a-sable/partage/{code}/supprimer', name: 'sandbox_shared_remove', requirements: ['code' => '[A-Za-z0-9]{10}'], methods: ['POST'], priority: 1)]
    public function remove(string $code, Request $request, #[CurrentUser] ?User $user): Response
    {
        $share = $this->shares->findOneBy(['code' => $code]);
        if (!$share || !$user || $share->getUser()?->getId() !== $user->getId()
            || !$this->isCsrfTokenValid('share-' . $code, (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->shares->remove($share);
        $this->em->flush();
        $this->addFlash('success', 'Lien supprimé.');
        return $this->redirectToRoute('studio');
    }

    private function share(string $code): SandboxShare
    {
        $share = $this->shares->live($code);
        if (!$share || !$share->getProject()->isPublished()) {
            throw new NotFoundHttpException();
        }
        return $share;
    }

    /** sign in with GitHub, then back to $back */
    private function signIn(Request $request, string $back): Response
    {
        $this->saveTargetPath($request->getSession(), 'main', $back);
        return $this->redirectToRoute('connect_github');
    }

    private function imported(int $count): string
    {
        return $count > 0 ? 'Importé dans votre espace : vos fichiers modifiés de ce jeu sont maintenant ceux-là. « Envoyer sur GitHub » ou « Forker pour garder » pour les publier.'
            : 'Rien à importer : aucun fichier modifié.';
    }
}
