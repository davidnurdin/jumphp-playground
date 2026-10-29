<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\ProjectRepository;
use App\Service\Voters;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the players say about a game, in three sections (Comment::SECTIONS): comments, the idea box, issues. Their
 * pages (for the games shown in their own site's frame), a new one (anyone; an anonymous one waits for the owner's
 * approval), the owner approving, removing them or marking an issue fixed, a writer removing theirs.
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class CommentController extends AbstractController
{
    private const SLUG = '[a-z][a-z0-9-]{1,38}[a-z0-9]';
    private const SECTION = 'commentaires|idees|problemes';
    /** from one address: at most this many in 10 minutes, and in a day */
    private const FLOOD = [5, 30];

    public function __construct(
        private ProjectRepository $projects,
        private CommentRepository $comments,
        private EntityManagerInterface $em,
        private Voters $voters,
        private string $kernelSecret,
    ) {
    }

    #[Route('/{slug}/{section}', name: 'game_comments', requirements: ['slug' => self::SLUG, 'section' => self::SECTION], methods: ['GET'], priority: -10)]
    public function list(string $slug, string $section, Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->page($this->published($slug), $section, $request, $user);
    }

    #[Route('/{slug}/{section}', name: 'game_comment_add', requirements: ['slug' => self::SLUG, 'section' => self::SECTION], methods: ['POST'], priority: -10)]
    public function add(string $slug, string $section, Request $request, #[CurrentUser] ?User $user, string $appUrl): Response
    {
        // a page of the portal, not another site (no CSRF token: the players have no session)
        $origin = (string)$request->headers->get('Origin');
        if ($origin !== rtrim($appUrl, '/') && $request->headers->get('Sec-Fetch-Site') !== 'same-origin') {
            throw new AccessDeniedHttpException('Origin');
        }
        $project = $this->published($slug);
        $body = trim(str_replace("\r\n", "\n", (string)$request->request->get('body')));
        $name = $user ? null : trim((string)preg_replace('/\s+/u', ' ', (string)$request->request->get('name')));
        // the field people do not see: a robot fills it
        if ((string)$request->request->get('website') !== '') {
            return $this->back($project, $section, $request);
        }
        $ipHash = hash('sha256', $request->getClientIp() . $this->kernelSecret);
        $error = match (true) {
            $body === '' => ['Écrivez quelque chose.', []],
            mb_strlen($body) > Comment::MAX_LENGTH => ['Votre message est trop long (%max% caractères au plus).', ['%max%' => Comment::MAX_LENGTH]],
            $name !== null && mb_strlen($name) > Comment::MAX_NAME => ['Votre nom est trop long (%max% caractères au plus).', ['%max%' => Comment::MAX_NAME]],
            $this->comments->countFrom($ipHash, new \DateTimeImmutable('-10 minutes')) >= self::FLOOD[0],
            $this->comments->countFrom($ipHash, new \DateTimeImmutable('-1 day')) >= self::FLOOD[1] => ['Trop de messages d\'un coup : réessayez plus tard.', []],
            default => null,
        };
        if ($error) {
            return $this->page($project, $section, $request, $user, ['body' => $body, 'name' => (string)$name], ['message' => $error[0], 'params' => $error[1]], 422);
        }
        $response = $this->back($project, $section, $request);
        $this->em->persist(new Comment($project, $body, $user, $user ? null : ($name ?: null),
            $user ? null : $this->voters->make($request, null, $response), $ipHash, Comment::SECTIONS[$section]));
        $this->em->flush();
        $this->projects->countComments($project);
        if ($user) {
            $this->addFlash('success', 'Message publié.');
        }
        return $response;
    }

    #[Route('/{slug}/commentaires/{id}/valider', name: 'game_comment_approve', requirements: ['slug' => self::SLUG, 'id' => '\d+'], methods: ['POST'], priority: -10)]
    #[IsGranted('ROLE_USER')]
    public function approve(string $slug, int $id, Request $request, #[CurrentUser] User $user): Response
    {
        $comment = $this->owned($slug, $id, $request, $user);
        $comment->approve();
        $this->em->flush();
        $this->projects->countComments($comment->getProject());
        $this->addFlash('success', 'Message validé : il est visible de tous.');
        return $this->back($comment->getProject(), $comment->getSection(), $request);
    }

    /** an issue fixed, or not after all */
    #[Route('/{slug}/commentaires/{id}/resolu', name: 'game_comment_resolve', requirements: ['slug' => self::SLUG, 'id' => '\d+'], methods: ['POST'], priority: -10)]
    #[IsGranted('ROLE_USER')]
    public function resolve(string $slug, int $id, Request $request, #[CurrentUser] User $user): Response
    {
        $comment = $this->owned($slug, $id, $request, $user);
        if (!$comment->isIssue()) {
            throw new NotFoundHttpException();
        }
        $comment->setResolved(!$comment->isResolved());
        $this->em->flush();
        return $this->back($comment->getProject(), $comment->getSection(), $request);
    }

    #[Route('/{slug}/commentaires/{id}/supprimer', name: 'game_comment_remove', requirements: ['slug' => self::SLUG, 'id' => '\d+'], methods: ['POST'], priority: -10)]
    #[IsGranted('ROLE_USER')]
    public function remove(string $slug, int $id, Request $request, #[CurrentUser] User $user): Response
    {
        $comment = $this->comment($slug, $id, $request);
        if (!$comment->canBeRemovedBy($user)) {
            throw $this->createAccessDeniedException();
        }
        $project = $comment->getProject();
        $this->em->remove($comment);
        $this->em->flush();
        $this->projects->countComments($project);
        $this->addFlash('success', 'Message supprimé.');
        return $this->back($project, $comment->getSection(), $request);
    }

    /**
     * @param array{body: string, name: string}|null $form
     * @param array{message: string, params: array<string, mixed>}|null $error
     */
    private function page(Project $project, string $section, Request $request, ?User $user, ?array $form = null, ?array $error = null, int $status = 200): Response
    {
        return $this->render('game/comments.html.twig', [
            'project' => $project,
            'section' => $section,
            'comments' => $this->comments->shownTo($project, Comment::SECTIONS[$section], $user, $this->voters->of($request, null)),
            'comment_form' => $form,
            'comment_error' => $error,
        ], new Response(status: $status));
    }

    private function published(string $slug): Project
    {
        $project = $this->projects->findOneBy(['slug' => $slug]);
        if (!$project || !$project->isPublished()) {
            throw new NotFoundHttpException();
        }
        return $project;
    }

    private function comment(string $slug, int $id, Request $request): Comment
    {
        $comment = $this->comments->find($id);
        if (!$comment || $comment->getProject()->getSlug() !== $slug) {
            throw new NotFoundHttpException();
        }
        if (!$this->isCsrfTokenValid('comment-' . $id, (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        return $comment;
    }

    /** one of the game's owner */
    private function owned(string $slug, int $id, Request $request, User $user): Comment
    {
        $comment = $this->comment($slug, $id, $request);
        if (!$comment->getProject()->isOwnedBy($user)) {
            throw $this->createAccessDeniedException();
        }
        return $comment;
    }

    /** back where the form was: the game's page (on that section), the section's page or the studio */
    private function back(Project $project, string $section, Request $request): RedirectResponse
    {
        $url = match ((string)$request->request->get('from')) {
            'list' => $this->generateUrl('game_comments', ['slug' => $project->getSlug(), 'section' => $section]),
            'studio' => $this->generateUrl('studio_project', ['slug' => $project->getSlug()]),
            default => $this->generateUrl('game', ['slug' => $project->getSlug()] + ($section !== 'commentaires' ? ['retours' => $section] : [])),
        };
        return $this->redirect($url . '#retours');
    }
}
