<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Project;
use App\Entity\User;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\Message\InitRepository;
use App\Message\SyncProject;
use App\Repository\DraftRepository;
use App\Security\TokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** What a creator does with their projects: create, import, fork, push the editor's drafts. */
final class Projects
{
    public function __construct(
        private GitHub $github,
        private TokenCipher $cipher,
        private Slugs $slugs,
        private DraftRepository $drafts,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private string $appUrl,
    ) {
    }

    public function token(User $user): string
    {
        if (!$user->getGithubToken()) {
            throw new ProjectException('Reconnectez-vous avec GitHub.');
        }
        return $this->cipher->decrypt($user->getGithubToken());
    }

    /** A new game: its GitHub repository (made now), then the project inside (InitRepository, a few seconds). */
    public function create(User $user, string $title, string $slug, Category $category, string $description, string $brief, string $kind = 'original'): Project
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 80) {
            throw new ProjectException('Le titre doit faire 1 à 80 caractères.');
        }
        if ($problem = $this->slugs->problem($slug)) {
            throw $problem;
        }
        $token = $this->token($user);
        if ($this->github->repo($user->getLogin() . '/' . $slug, $token)) {
            throw new ProjectException('Vous avez déjà un dépôt GitHub « %slug% » : choisissez un autre nom, ou importez ce dépôt.', ['%slug%' => $slug]);
        }
        try {
            $repo = $this->github->createRepo($token, $slug, $description ?: $title, $this->pageUrl($slug));
        } catch (GitHubException $e) {
            throw new ProjectException($e->getMessage());
        }
        $project = new Project($user, $slug, $title, $category, $repo['full_name'], 'games/' . $slug);
        $project->setDescription(trim($description));
        $project->setBrief(trim($brief));
        $project->setKind($kind);
        $project->setListed(false);
        $this->em->persist($project);
        $this->em->flush();
        $this->bus->dispatch(new InitRepository($project->getId(), InitRepository::NEW));
        return $project;
    }

    /** An existing JumPHP project of GitHub (public): published from its repository. */
    public function import(User $user, string $repoFullName, string $slug, Category $category, ?string $token = null): Project
    {
        $repoFullName = trim(preg_replace('#^https?://github\.com/|\.git$#', '', trim($repoFullName)), '/');
        if (!preg_match('#^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$#', $repoFullName)) {
            throw new ProjectException('Dépôt GitHub attendu : propriétaire/nom.');
        }
        if ($problem = $this->slugs->problem($slug)) {
            throw $problem;
        }
        $repo = $this->github->repo($repoFullName, $token);
        if (!$repo || !empty($repo['private'])) {
            throw new ProjectException('Le dépôt %repo% est introuvable ou privé.', ['%repo%' => $repoFullName]);
        }
        if ($token !== null && empty($repo['permissions']['push'])) {
            throw new ProjectException('Vous n\'avez pas les droits d\'écriture sur %repo% : forkez-le d\'abord sur GitHub.', ['%repo%' => $repoFullName]);
        }
        $project = new Project($user, $slug, $repo['name'], $category, $repo['full_name'], 'games/' . $slug);
        $project->setDescription((string)($repo['description'] ?? ''));
        $project->setDefaultBranch($repo['default_branch'] ?? 'main');
        $this->em->persist($project);
        $this->em->flush();
        $this->bus->dispatch(new InitRepository($project->getId(), InitRepository::IMPORT));
        return $project;
    }

    /** A copy of someone's game in the user's GitHub account (a fork), with the user's drafts moved to it. */
    public function fork(User $user, Project $source, string $slug): Project
    {
        if ($problem = $this->slugs->problem($slug)) {
            throw $problem;
        }
        $token = $this->token($user);
        if ($this->github->repo($user->getLogin() . '/' . $slug, $token)) {
            throw new ProjectException('Vous avez déjà un dépôt GitHub « %slug% ».', ['%slug%' => $slug]);
        }
        try {
            $repo = $this->github->fork($token, $source->getRepoFullName(), $slug);
        } catch (GitHubException $e) {
            throw new ProjectException($e->getMessage());
        }
        $project = new Project($user, $slug, $source->getTitle(), $source->getCategory(), $repo['full_name'], $source->getGameDir());
        $project->setDescription($source->getDescription());
        $project->setForkOf($source);
        $project->setListed(false);
        $this->em->persist($project);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE draft SET project_id = ? WHERE project_id = ? AND user_id = ?',
            [$project->getId(), $source->getId(), $user->getId()]);
        $this->bus->dispatch(new InitRepository($project->getId(), InitRepository::FORK));
        return $project;
    }

    /** The user's drafts of $project as one commit on its branch; the game is published again. */
    public function push(User $user, Project $project, string $message): string
    {
        $drafts = $this->drafts->of($user, $project);
        if (!$drafts) {
            throw new ProjectException('Aucune modification à envoyer.');
        }
        $token = $this->token($user);
        if (!$this->github->canPush($project->getRepoFullName(), $token)) {
            throw new ProjectException('Vous ne pouvez pas écrire dans %repo% : forkez le jeu pour garder vos modifications.', ['%repo%' => $project->getRepoFullName()]);
        }
        $files = [];
        foreach ($drafts as $path => $draft) {
            $files[$project->inRepo($path)] = $draft->isDeleted() ? null : (string)$draft->getContent();
        }
        $message = trim($message) ?: 'Changes from the JumPHP playground editor';
        try {
            $sha = $this->github->commit($token, $project->getRepoFullName(), $project->getDefaultBranch(), $files, $message);
        } catch (GitHubException $e) {
            throw new ProjectException($e->getMessage());
        }
        $this->drafts->clear($user, $project);
        $this->bus->dispatch(new SyncProject($project->getId()));
        return $sha;
    }

    public function pageUrl(string $slug): string
    {
        return rtrim($this->appUrl, '/') . '/' . $slug;
    }
}
