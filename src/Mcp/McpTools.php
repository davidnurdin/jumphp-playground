<?php

namespace App\Mcp;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use App\Service\GameFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Publisher;
use App\Service\Slugs;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the creator's Claude can do on the playground. It writes the game in a local clone of the project
 * (JumPHP's tools need Docker: tests, screenshots), pushes to GitHub, and these tools create the project,
 * publish it, report the errors and show it in the directory.
 */
final class McpTools
{
    public function __construct(
        private Projects $projectService,
        private ProjectRepository $projects,
        private CategoryRepository $categories,
        private Publisher $publisher,
        private GameFiles $files,
        private Slugs $slugs,
        private EntityManagerInterface $em,
        private string $appUrl,
    ) {
    }

    public function instructions(): string
    {
        return <<<TXT
JumPHP playground ({$this->appUrl}): a site of retro games written in PHP with JumPHP, played in the browser.
A project is a public GitHub repository of the user (a JumPHP project, the game in games/<id>/); the
playground publishes it after each push to its main branch.

To make a game for the user:
1. create_project (title, description, category from list_categories, and the brief: what the user wants),
   then get_project until its status is no longer "creating" (a few seconds).
2. git clone the repository (clone_url), then work in it as JumPHP's AGENTS.md says: bin/jumphp install <id>,
   write the game in games/<id>/, bin/jumphp test, look at the screenshots (bin/jumphp shot), browser build.
   Read games/<id>/BRIEF.md: the user's request and the playground's rules.
3. Make the game's home page in games/<id>/site/ (AGENTS.md section 13: the game's own graphics, SEO, a link
   to play/; check it with bin/jumphp siteshot <id>) and games/<id>/assets/thumbnail.png.
4. git push, then sync_project: it publishes at once and returns errors and warnings (a commit with a PHP
   syntax error is not published). Fix and push again until it is "published".
5. When the game is good: update_project with listed=true (it appears in the directory). Give the user the URL.
Never put the MCP token or any secret in the repository.
TXT;
    }

    public function definitions(): array
    {
        $slug = ['type' => 'string', 'description' => 'the project\'s name in its URL (' . rtrim($this->appUrl, '/') . '/<slug>)'];
        return [
            $this->tool('whoami', 'The signed-in creator and the playground\'s addresses.', [], [], true),
            $this->tool('list_categories', 'The categories of the games directory (use a slug in create_project).', [], [], true),
            $this->tool('list_projects', 'The creator\'s projects with their status and URLs.', [], [], true),
            $this->tool('create_project', 'Create a new game: a public GitHub repository of the creator holding a JumPHP project with one game made from the skeleton and BRIEF.md (what the game should be). Asynchronous: call get_project until status is not "creating".', [
                'title' => ['type' => 'string', 'description' => 'the game\'s title (80 characters at most)'],
                'description' => ['type' => 'string', 'description' => 'one or two sentences for the directory'],
                'category' => ['type' => 'string', 'description' => 'a category slug (list_categories)'],
                'brief' => ['type' => 'string', 'description' => 'what the game should be: the user\'s request, as complete as possible'],
                'slug' => ['type' => 'string', 'description' => 'optional: URL name and repository name (a-z, 0-9, -); default from the title'],
            ], ['title', 'description', 'category', 'brief']),
            $this->tool('get_project', 'A project: status (creating, empty, ready, error), last publication, errors, repository, clone URL, game folder, URLs.', ['slug' => $slug], ['slug'], true),
            $this->tool('update_project', 'Change a project\'s title, description, category, brief, or listed (true = shown in the directory).', [
                'slug' => $slug,
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'brief' => ['type' => 'string'],
                'listed' => ['type' => 'boolean'],
            ], ['slug']),
            $this->tool('sync_project', 'Publish the last commit of the project\'s branch now (after git push) and return the result: published / unchanged / failed, errors (PHP syntax, game.yaml...) and warnings.', ['slug' => $slug], ['slug']),
            $this->tool('import_project', 'Publish an existing public JumPHP project of GitHub that the creator can push to.', [
                'repository' => ['type' => 'string', 'description' => 'owner/name'],
                'slug' => $slug,
                'category' => ['type' => 'string'],
            ], ['repository', 'slug', 'category']),
            $this->tool('list_games', 'The public games of the directory (for ideas, or to read how others did something).', [
                'category' => ['type' => 'string'],
                'search' => ['type' => 'string'],
            ], [], true),
            $this->tool('list_game_files', 'The published files of any public game (paths and sizes).', ['slug' => $slug], ['slug'], true),
            $this->tool('read_game_file', 'A published text file of any public game (source code, config, site...).', [
                'slug' => $slug,
                'path' => ['type' => 'string', 'description' => 'path in the game folder, e.g. src/Scene/PlayScene.php'],
            ], ['slug', 'path'], true),
        ];
    }

    private function tool(string $name, string $description, array $properties, array $required = [], bool $readOnly = false): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => (object)$properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => $readOnly, 'destructiveHint' => false, 'openWorldHint' => !$readOnly],
        ];
    }

    public function has(string $name): bool
    {
        return in_array($name, array_column($this->definitions(), 'name'), true);
    }

    public function call(string $name, array $args, User $user): array
    {
        try {
            return match ($name) {
                'whoami' => [
                    'login' => $user->getLogin(),
                    'name' => $user->getDisplayName(),
                    'projects' => count($this->projects->ownedBy($user)),
                    'playground' => $this->appUrl,
                    'studio' => rtrim($this->appUrl, '/') . '/studio',
                ],
                'list_categories' => array_map(fn ($c) => ['slug' => $c->getSlug(), 'name' => $c->getName(), 'description' => $c->getDescription()], $this->categories->ordered()),
                'list_projects' => array_map(fn (Project $p) => $this->summary($p), $this->projects->ownedBy($user)),
                'create_project' => $this->create($args, $user),
                'get_project' => $this->details($this->owned($args, $user)),
                'update_project' => $this->update($args, $user),
                'sync_project' => $this->sync($args, $user),
                'import_project' => $this->details($this->projectService->import($user, $this->string($args, 'repository'),
                    $this->string($args, 'slug'), $this->category($args), $this->projectService->token($user))),
                'list_games' => $this->games($args),
                'list_game_files' => $this->gameFiles($args),
                'read_game_file' => $this->readFile($args),
                default => throw new McpToolError("Unknown tool: $name"),
            };
        } catch (ProjectException $e) {
            throw new McpToolError($e->getMessage());
        }
    }

    private function create(array $args, User $user): array
    {
        $title = $this->string($args, 'title');
        $slug = trim((string)($args['slug'] ?? '')) ?: $this->slugs->available(Slugs::fromTitle($title));
        $project = $this->projectService->create($user, $title, $slug, $this->category($args),
            $this->string($args, 'description'), $this->string($args, 'brief'));
        return $this->details($project) + ['next' => 'The repository is being made: call get_project in a few seconds, until status is "empty" or "ready", then git clone it.'];
    }

    private function update(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        if (isset($args['title'])) {
            $project->setTitle(mb_substr(trim((string)$args['title']), 0, 80) ?: $project->getTitle());
        }
        if (isset($args['description'])) {
            $project->setDescription(trim((string)$args['description']));
        }
        if (isset($args['brief'])) {
            $project->setBrief(trim((string)$args['brief']));
        }
        if (isset($args['category'])) {
            $project->setCategory($this->category($args));
        }
        if (isset($args['listed'])) {
            if ($args['listed'] && !$project->isPublished()) {
                throw new McpToolError('The game is not published yet: push it and call sync_project first.');
            }
            $project->setListed((bool)$args['listed']);
        }
        $this->em->flush();
        return $this->details($project);
    }

    private function sync(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        if ($project->getStatus() === Project::STATUS_CREATING) {
            throw new McpToolError('The repository is still being made: call get_project until its status is not "creating".');
        }
        $result = $this->publisher->sync($project);
        return $result->toArray() + ['page' => $this->projectService->pageUrl($project->getSlug()), 'play' => $this->playUrl($project)];
    }

    private function games(array $args): array
    {
        $search = trim((string)($args['search'] ?? ''));
        $category = isset($args['category']) && $args['category'] !== '' ? $this->category($args) : null;
        $games = $search !== '' ? $this->projects->search($search) : $this->projects->latest(48, $category);
        return array_map(fn (Project $p) => [
            'slug' => $p->getSlug(), 'title' => $p->getTitle(), 'description' => $p->getDescription(),
            'category' => $p->getCategory()->getSlug(), 'author' => $p->getOwner()->getLogin(),
            'repository' => $p->getRepoUrl(), 'page' => $this->projectService->pageUrl($p->getSlug()),
        ], $games);
    }

    private function gameFiles(array $args): array
    {
        $project = $this->public($args);
        $files = [];
        foreach ($this->files->tree($project) as $path => $size) {
            $files[] = ['path' => $path, 'size' => $size];
        }
        return ['slug' => $project->getSlug(), 'commit' => $project->getPublishedSha(), 'files' => $files];
    }

    private function readFile(array $args): array
    {
        $project = $this->public($args);
        $path = $this->string($args, 'path');
        $file = $this->files->path($project, $path);
        if (!$file) {
            throw new McpToolError("No file $path in {$project->getSlug()} (list_game_files).");
        }
        if (!GameFiles::isText($path) || filesize($file) > GameFiles::MAX_EDIT) {
            throw new McpToolError("$path is not a text file (or is bigger than 512 KB).");
        }
        return ['slug' => $project->getSlug(), 'path' => GameFiles::clean($path), 'content' => (string)file_get_contents($file)];
    }

    private function summary(Project $p): array
    {
        return [
            'slug' => $p->getSlug(),
            'title' => $p->getTitle(),
            'status' => $p->getStatus(),
            'listed' => $p->isListed(),
            'repository' => $p->getRepoUrl(),
            'page' => $this->projectService->pageUrl($p->getSlug()),
        ];
    }

    private function details(Project $p): array
    {
        return $this->summary($p) + [
            'description' => $p->getDescription(),
            'category' => $p->getCategory()->getSlug(),
            'status_message' => $p->getStatusMessage(),
            'clone_url' => $p->getCloneUrl(),
            'branch' => $p->getDefaultBranch(),
            'game_folder' => $p->getGameDir(),
            'game_id' => $p->getGameId(),
            'published_commit' => $p->getPublishedSha(),
            'published_at' => $p->getPublishedAt()?->format(DATE_ATOM),
            'engine' => $p->getEngineVersion(),
            'has_site' => $p->hasSite(),
            'play' => $this->playUrl($p),
            'brief' => $p->getBrief(),
        ];
    }

    private function playUrl(Project $p): string
    {
        return rtrim($this->appUrl, '/') . '/' . $p->getSlug() . '/jouer';
    }

    private function owned(array $args, User $user): Project
    {
        $project = $this->projects->findOneBy(['slug' => $this->string($args, 'slug')]);
        if (!$project || !$project->isOwnedBy($user)) {
            throw new McpToolError('No project "' . ($args['slug'] ?? '') . '" of yours (list_projects).');
        }
        return $project;
    }

    private function public(array $args): Project
    {
        $project = $this->projects->findOneBy(['slug' => $this->string($args, 'slug')]);
        if (!$project || !$project->isPublished()) {
            throw new McpToolError('No published game "' . ($args['slug'] ?? '') . '" (list_games).');
        }
        return $project;
    }

    private function category(array $args): \App\Entity\Category
    {
        $category = $this->categories->findOneBy(['slug' => $this->string($args, 'category')]);
        if (!$category) {
            throw new McpToolError('Unknown category "' . $args['category'] . '": ' . implode(', ', array_map(fn ($c) => $c->getSlug(), $this->categories->ordered())));
        }
        return $category;
    }

    private function string(array $args, string $key): string
    {
        $value = trim((string)($args[$key] ?? ''));
        if ($value === '') {
            throw new McpToolError("Missing argument: $key");
        }
        return $value;
    }
}
