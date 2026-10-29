<?php

namespace App\Mcp;

use App\Entity\Comment;
use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\CommentRepository;
use App\Repository\NoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\TodoRepository;
use App\Message\ShootProject;
use App\Service\GameFiles;
use App\Service\PrivateFiles;
use App\Service\ProjectException;
use App\Service\Projects;
use App\Service\Publisher;
use App\Service\Slugs;
use App\Service\UploadToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
        private PrivateFiles $private,
        private UploadToken $uploadTokens,
        private Slugs $slugs,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private NoteRepository $notes,
        private TodoRepository $todos,
        private CommentRepository $comments,
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
1. create_project (title, description, category from list_categories, kind, and the brief: what the user wants;
   kind "cpc" for the remake of an Amstrad CPC game from its .dsk: then dsk2jumphp does the extraction, see
   BRIEF.md and AGENTS.md section 12, and ask the user for the path of their .dsk),
   then get_project until its status is no longer "creating" (a few seconds).
2. git clone the repository (clone_url), then work in it as JumPHP's AGENTS.md says: bin/jumphp install <id>,
   write the game in games/<id>/, bin/jumphp test, look at the screenshots (bin/jumphp shot), browser build.
   Read games/<id>/BRIEF.md: the user's request and the playground's rules.
3. Make the game's home page in games/<id>/site/ (AGENTS.md section 13: the game's own graphics, SEO, a link
   to play/; check it with bin/jumphp siteshot <id>) and games/<id>/assets/thumbnail.png.
4. git push, then sync_project: it publishes at once and returns errors and warnings (a commit with a PHP
   syntax error is not published). Fix and push again until it is "published".
5. When the game is good: update_project with listed=true (it appears in the directory). Give the user the URL.
Files the game needs that may not be public (the data or sounds of an original game): keep them out of git and
send them as private files (upload_private_files gives a URL to send them to with curl); the game loads them
like its own files, no repository, source page or fork holds them.
Never put the MCP token or any secret in the repository.

Private memory of a project (only its owner sees it, never published): notes and a todo list. At the start of a
session on a project, get_notes and list_todos to know where it stands; while working, add_todos for what is left
and update_todos when something is done; at the end, save_note (name "progress") with where the game stands, what
was decided and what comes next, so the next session (or another computer) picks it up.
The players' comments, ideas (the idea box) and issues on the game: list_feedback; the owner approves the
anonymous ones (moderate_feedback), and marks an issue resolved once its fix is published.
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
                'kind' => ['type' => 'string', 'enum' => array_keys(Project::KINDS), 'description' => 'original (a new game, default), cpc (a remake of an Amstrad CPC game from its .dsk disc: made with dsk2jumphp on the user\'s computer), port (a port of an existing game whose source code exists)'],
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
            $this->tool('list_private_files', 'A project\'s private files: files the game loads that are in no repository, source page or fork (the data of an original game). Paths are relative to the game folder.', ['slug' => $slug], ['slug'], true),
            $this->tool('upload_private_files', 'A URL to send private files to (valid ' . intdiv(UploadToken::TTL, 3600) . ' hours), with curl from the user\'s computer: the answer gives the commands. One file, several, or a zip archive of a folder. At most ' . intdiv(PrivateFiles::MAX_FILE, 1048576) . ' MB per file, ' . intdiv(PrivateFiles::MAX_TOTAL, 1048576) . ' MB and ' . PrivateFiles::MAX_COUNT . ' files per game. A file sent again replaces the previous one.', ['slug' => $slug], ['slug']),
            $this->tool('remove_private_files', 'Remove private files of a project (paths from list_private_files), or all of them.', [
                'slug' => $slug,
                'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'paths in the game folder'],
                'all' => ['type' => 'boolean', 'description' => 'true: remove every private file'],
            ], ['slug']),
            $this->tool('save_note', 'Save a private note of a project (only its owner sees it): where the game stands, decisions, what is next. Replaces the note of that name, or appends to it. At most ' . Note::MAX_COUNT . ' notes of ' . intdiv(Note::MAX_SIZE, 1000) . ' KB per project.', [
                'slug' => $slug,
                'name' => ['type' => 'string', 'description' => 'the note\'s name (default "' . Note::DEFAULT . '": the state of the project)'],
                'content' => ['type' => 'string', 'description' => 'the text (Markdown)'],
                'append' => ['type' => 'boolean', 'description' => 'true: add to the end of the note instead of replacing it'],
            ], ['slug', 'content']),
            $this->tool('get_notes', 'A project\'s private notes: with a name, that note\'s text; without, the "' . Note::DEFAULT . '" note\'s text and the names of the others.', [
                'slug' => $slug,
                'name' => ['type' => 'string'],
            ], ['slug'], true),
            $this->tool('delete_note', 'Delete a private note of a project.', ['slug' => $slug, 'name' => ['type' => 'string']], ['slug', 'name']),
            $this->tool('add_todos', 'Add tasks to a project\'s private todo list.', [
                'slug' => $slug,
                'items' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'the tasks, ' . Todo::MAX_TEXT . ' characters at most each'],
            ], ['slug', 'items']),
            $this->tool('list_todos', 'A project\'s private todo list: the tasks left (id, text), and the ones done with all=true.', [
                'slug' => $slug,
                'all' => ['type' => 'boolean', 'description' => 'true: the tasks done too'],
            ], ['slug'], true),
            $this->tool('update_todos', 'Check tasks done (or not), change their text, remove them (ids from list_todos).', [
                'slug' => $slug,
                'done' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids of the tasks done'],
                'undone' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids of tasks to do again'],
                'remove' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'ids of tasks to delete'],
                'text' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'new texts: {"<id>": "text"}'],
            ], ['slug']),
            $this->tool('list_feedback', 'What players say about one of your games: comments, ideas (the idea box) and issues (problems), with those waiting for your approval (anonymous ones).', [
                'slug' => $slug,
                'section' => ['type' => 'string', 'enum' => ['comment', 'idea', 'issue'], 'description' => 'only one kind (default: all)'],
            ], ['slug'], true),
            $this->tool('moderate_feedback', 'On one of your games: approve an anonymous message (it becomes public), remove one, mark an issue resolved or open again.', [
                'slug' => $slug,
                'id' => ['type' => 'integer', 'description' => 'from list_feedback'],
                'action' => ['type' => 'string', 'enum' => ['approve', 'remove', 'resolve', 'reopen']],
            ], ['slug', 'id', 'action']),
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
                'list_private_files' => $this->privateFiles($this->owned($args, $user)),
                'upload_private_files' => $this->uploadUrl($args, $user),
                'remove_private_files' => $this->removePrivate($args, $user),
                'save_note' => $this->saveNote($args, $user),
                'get_notes' => $this->getNotes($args, $user),
                'delete_note' => $this->deleteNote($args, $user),
                'add_todos' => $this->addTodos($args, $user),
                'list_todos' => $this->listTodos($this->owned($args, $user), !empty($args['all'])),
                'update_todos' => $this->updateTodos($args, $user),
                'list_feedback' => $this->feedback($args, $user),
                'moderate_feedback' => $this->moderate($args, $user),
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
            $this->string($args, 'description'), $this->string($args, 'brief'), (string)($args['kind'] ?? 'original'));
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

    private function privateFiles(Project $project): array
    {
        $index = $this->private->index($project);
        $files = [];
        foreach ($index as $path => $file) {
            $files[] = ['path' => $path, 'size' => $file['size'], 'hash' => $file['hash']];
        }
        return [
            'slug' => $project->getSlug(),
            'files' => $files,
            'count' => count($files),
            'size' => array_sum(array_column($index, 'size')),
            'limits' => ['file' => PrivateFiles::MAX_FILE, 'total' => PrivateFiles::MAX_TOTAL, 'count' => PrivateFiles::MAX_COUNT],
        ];
    }

    private function uploadUrl(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $url = rtrim($this->appUrl, '/') . '/upload/' . $this->uploadTokens->make((int)$project->getId(), (int)$user->getId());
        return [
            'slug' => $project->getSlug(),
            'url' => $url,
            'expires_in' => UploadToken::TTL,
            'one_file' => "curl -fsS -F path=assets/data/LEVEL0.SQZ -F file=@/local/LEVEL0.SQZ '$url'",
            'several_files' => "curl -fsS -F 'path[]=assets/data/A.BIN' -F 'file[]=@/local/A.BIN' -F 'path[]=assets/sfx/b.ogg' -F 'file[]=@/local/b.ogg' '$url'",
            'zip_of_a_folder' => "(cd /local/data && zip -qr /tmp/data.zip .) && curl -fsS -F folder=assets/data -F archive=@/tmp/data.zip '$url'",
            'notes' => 'path = the file\'s path in the game folder (as the game loads it). One request at most ' . intdiv(PrivateFiles::MAX_FILE, 1048576) . ' MB: split bigger sets into several requests. '
                . 'The answer is JSON: added paths, or error. The game loads them at once (no push or sync needed); never commit them.',
        ];
    }

    private function removePrivate(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        if (!empty($args['all'])) {
            $this->private->removeAll($project);
        } else {
            $paths = array_map('strval', (array)($args['paths'] ?? []));
            if (!$paths) {
                throw new McpToolError('Give paths (list_private_files) or all=true.');
            }
            $index = $this->private->index($project);
            foreach ($paths as $path) {
                if (!isset($index[(string)GameFiles::clean($path)])) {
                    throw new McpToolError("No private file $path (list_private_files).");
                }
            }
            foreach ($paths as $path) {
                $this->private->remove($project, $path);
            }
        }
        if ($project->isPublished() && (!$project->hasThumbnail() || $project->isShotThumbnail())) {
            $this->bus->dispatch(new ShootProject((int)$project->getId(), true));
        }
        return $this->privateFiles($project);
    }

    private function noteName(array $args): string
    {
        $name = trim((string)($args['name'] ?? '')) ?: Note::DEFAULT;
        if (!preg_match('/^[\w .\/-]{1,60}$/u', $name)) {
            throw new McpToolError('A note\'s name: 1 to 60 letters, digits, spaces, . / - _');
        }
        return $name;
    }

    private function saveNote(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $name = $this->noteName($args);
        $content = (string)($args['content'] ?? '');
        $note = $this->notes->findOneBy(['project' => $project, 'name' => $name]);
        if (!$note) {
            if ($this->notes->count(['project' => $project]) >= Note::MAX_COUNT) {
                throw new McpToolError('At most ' . Note::MAX_COUNT . ' notes per project: delete_note some, or append to one.');
            }
            $this->em->persist($note = new Note($project, $name));
        }
        if (!empty($args['append']) && $note->getContent() !== '') {
            $content = rtrim($note->getContent(), "\n") . "\n\n" . $content;
        }
        if (strlen($content) > Note::MAX_SIZE) {
            throw new McpToolError('A note holds ' . intdiv(Note::MAX_SIZE, 1000) . ' KB at most: shorten it, or split it into several names.');
        }
        $note->setContent($content);
        $this->em->flush();
        return ['slug' => $project->getSlug(), 'name' => $name, 'size' => strlen($content), 'updated_at' => $note->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function getNotes(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $all = $this->notes->findBy(['project' => $project], ['name' => 'ASC']);
        $asked = trim((string)($args['name'] ?? ''));
        $name = $asked !== '' ? $asked : Note::DEFAULT;
        $note = array_values(array_filter($all, fn (Note $n) => $n->getName() === $name))[0] ?? null;
        if (!$note && $asked !== '') {
            throw new McpToolError("No note \"$asked\" (get_notes without a name lists them).");
        }
        return [
            'slug' => $project->getSlug(),
            'name' => $name,
            'content' => $note?->getContent(),
            'updated_at' => $note?->getUpdatedAt()->format(DATE_ATOM),
            'notes' => array_map(fn (Note $n) => ['name' => $n->getName(), 'size' => strlen($n->getContent()), 'updated_at' => $n->getUpdatedAt()->format(DATE_ATOM)], $all),
            'todos_left' => $this->todos->count(['project' => $project, 'done' => false]),
        ];
    }

    private function deleteNote(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $note = $this->notes->findOneBy(['project' => $project, 'name' => $this->string($args, 'name')])
            ?? throw new McpToolError('No note "' . $args['name'] . '".');
        $this->em->remove($note);
        $this->em->flush();
        return ['slug' => $project->getSlug(), 'deleted' => $note->getName()];
    }

    private function addTodos(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $items = array_values(array_filter(array_map(fn ($t) => trim((string)$t), (array)($args['items'] ?? [])), fn ($t) => $t !== ''));
        if (!$items) {
            throw new McpToolError('Give the tasks in items.');
        }
        if ($this->todos->count(['project' => $project]) + count($items) > Todo::MAX_COUNT) {
            throw new McpToolError('At most ' . Todo::MAX_COUNT . ' tasks per project: remove the old ones done (update_todos remove).');
        }
        foreach ($items as $text) {
            $this->em->persist(new Todo($project, mb_substr($text, 0, Todo::MAX_TEXT)));
        }
        $this->em->flush();
        return $this->listTodos($project, false);
    }

    private function listTodos(Project $project, bool $all): array
    {
        $todos = $this->todos->findBy(['project' => $project] + ($all ? [] : ['done' => false]), ['done' => 'ASC', 'id' => 'ASC']);
        return [
            'slug' => $project->getSlug(),
            'todos' => array_map(fn (Todo $t) => ['id' => $t->getId(), 'text' => $t->getText(), 'done' => $t->isDone()]
                + ($t->isDone() ? ['done_at' => $t->getDoneAt()?->format(DATE_ATOM)] : []), $todos),
            'left' => $this->todos->count(['project' => $project, 'done' => false]),
            'done' => $this->todos->count(['project' => $project, 'done' => true]),
        ];
    }

    private function updateTodos(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $todo = function (mixed $id) use ($project): Todo {
            $todo = $this->todos->find((int)$id);
            if (!$todo || $todo->getProject()->getId() !== $project->getId()) {
                throw new McpToolError("No task $id in this project (list_todos all=true).");
            }
            return $todo;
        };
        foreach ((array)($args['done'] ?? []) as $id) {
            $todo($id)->setDone(true);
        }
        foreach ((array)($args['undone'] ?? []) as $id) {
            $todo($id)->setDone(false);
        }
        foreach ((array)($args['text'] ?? []) as $id => $text) {
            $todo($id)->setText(mb_substr(trim((string)$text), 0, Todo::MAX_TEXT));
        }
        foreach ((array)($args['remove'] ?? []) as $id) {
            $this->em->remove($todo($id));
        }
        $this->em->flush();
        return $this->listTodos($project, false);
    }

    private function feedback(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $kinds = isset($args['section']) && in_array($args['section'], Comment::SECTIONS, true) ? [$args['section']] : array_values(Comment::SECTIONS);
        $out = ['slug' => $project->getSlug()];
        foreach ($kinds as $kind) {
            $out[$kind] = array_map(fn (Comment $c) => [
                'id' => $c->getId(),
                'author' => $c->getAuthor()?->getLogin(),
                'name' => $c->isAnonymous() ? ($c->getName() ?? 'anonymous') : $c->getAuthor()?->getDisplayName(),
                'text' => $c->getBody(),
                'approved' => $c->isApproved(),
                'date' => $c->getCreatedAt()->format(DATE_ATOM),
            ] + ($c->isIssue() ? ['resolved' => $c->isResolved()] : []), $this->comments->shownTo($project, $kind, $user, null));
        }
        return $out;
    }

    private function moderate(array $args, User $user): array
    {
        $project = $this->owned($args, $user);
        $comment = $this->comments->find((int)($args['id'] ?? 0));
        if (!$comment || $comment->getProject()->getId() !== $project->getId()) {
            throw new McpToolError('No message ' . ($args['id'] ?? '') . ' on this game (list_feedback).');
        }
        $action = (string)($args['action'] ?? '');
        if (in_array($action, ['resolve', 'reopen'], true) && !$comment->isIssue()) {
            throw new McpToolError('Only an issue is resolved or reopened.');
        }
        match ($action) {
            'approve' => $comment->approve(),
            'remove' => $this->em->remove($comment),
            'resolve' => $comment->setResolved(true),
            'reopen' => $comment->setResolved(false),
            default => throw new McpToolError('action: approve, remove, resolve or reopen'),
        };
        $this->em->flush();
        $this->projects->countComments($project);
        return ['slug' => $project->getSlug(), 'id' => (int)$args['id'], 'done' => $action];
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
            'kind' => $p->getKind(),
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
