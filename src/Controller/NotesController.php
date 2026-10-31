<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\Project;
use App\Entity\Todo;
use App\Entity\User;
use App\Repository\NoteRepository;
use App\Repository\ProjectRepository;
use App\Repository\TodoRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A game's private memory in the studio, for its owner only: the notes and the todo list their Claude keeps (MCP
 * save_note / add_todos / update_todos), read as Markdown, and changed by hand too.
 */
#[Route('/studio/projet/{slug}/notes')]
final class NotesController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projects,
        private NoteRepository $notes,
        private TodoRepository $todos,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'studio_notes', methods: ['GET'])]
    public function show(string $slug, Request $request, #[CurrentUser] User $user): Response
    {
        $project = $this->owned($slug, $user);
        $notes = $this->notes->findBy(['project' => $project], ['updatedAt' => 'DESC']);
        usort($notes, fn (Note $a, Note $b) => ($b->getName() === Note::DEFAULT) <=> ($a->getName() === Note::DEFAULT));
        $markdown = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $asked = (string)$request->query->get('note');
        $current = array_values(array_filter($notes, fn (Note $n) => $n->getName() === $asked))[0] ?? $notes[0] ?? null;
        return $this->render('studio/notes.html.twig', [
            'project' => $project,
            'notes' => $notes,
            'current' => $current,
            'html' => $current ? (string)$markdown->convert($current->getContent()) : '',
            'edit' => $request->query->has('modifier') || $request->query->has('nouvelle'),
            'new' => $request->query->has('nouvelle'),
            'todos' => $this->todos->findBy(['project' => $project], ['done' => 'ASC', 'id' => 'ASC']),
            'max_size' => Note::MAX_SIZE,
            'max_todo' => Todo::MAX_TEXT,
        ]);
    }

    /** Saves a note (a new one, or renamed), or deletes it. */
    #[Route('/note', name: 'studio_notes_save', methods: ['POST'])]
    public function save(string $slug, Request $request, #[CurrentUser] User $user): Response
    {
        $project = $this->checked($slug, $request, $user);
        $old = (string)$request->request->get('old');
        $note = $old !== '' ? $this->notes->findOneBy(['project' => $project, 'name' => $old]) : null;
        if ($request->request->has('delete')) {
            if ($note) {
                $this->em->remove($note);
                $this->em->flush();
                $this->addFlash('success', 'Note supprimée.');
            }
            return $this->redirectToRoute('studio_notes', ['slug' => $slug]);
        }
        $name = trim((string)$request->request->get('name')) ?: Note::DEFAULT;
        $content = str_replace("\r\n", "\n", (string)$request->request->get('content'));
        $back = function (string $error) use ($slug, $old, $note): Response {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('studio_notes', ['slug' => $slug, 'note' => $old ?: null, $note ? 'modifier' : 'nouvelle' => 1]);
        };
        if (!preg_match('/^[\w .\/-]{1,60}$/u', $name)) {
            return $back('Le nom d\'une note : 1 à 60 lettres, chiffres, espaces, . / - _');
        }
        if (strlen($content) > Note::MAX_SIZE) {
            return $back('Une note fait 200 Ko au plus.');
        }
        $other = $this->notes->findOneBy(['project' => $project, 'name' => $name]);
        if ($other && $other !== $note) {
            return $back('Une autre note porte déjà ce nom.');
        }
        if (!$note) {
            if ($this->notes->count(['project' => $project]) >= Note::MAX_COUNT) {
                return $back('50 notes au plus par projet.');
            }
            $this->em->persist($note = new Note($project, $name));
        } elseif ($name !== $note->getName()) {
            // the name is the note's key: a renamed note is a new row with the same text
            $this->em->remove($note);
            $this->em->flush();
            $this->em->persist($note = new Note($project, $name));
        }
        $note->setContent($content);
        $this->em->flush();
        $this->addFlash('success', 'Note enregistrée.');
        return $this->redirectToRoute('studio_notes', ['slug' => $slug, 'note' => $name]);
    }

    /** The todo list: add tasks (one per line), check / uncheck one, delete one, or clear the done ones. */
    #[Route('/taches', name: 'studio_notes_todos', methods: ['POST'])]
    public function todos(string $slug, Request $request, #[CurrentUser] User $user): Response
    {
        $project = $this->checked($slug, $request, $user);
        $todo = null;
        if ($id = $request->request->getInt('id')) {
            $todo = $this->todos->findOneBy(['project' => $project, 'id' => $id]) ?? throw new NotFoundHttpException();
        }
        $action = (string)$request->request->get('action');
        if ($action === 'add') {
            $this->add($project, (string)$request->request->get('text'));
        } elseif ($action === 'toggle' && $todo) {
            $todo->setDone(!$todo->isDone());
        } elseif ($action === 'delete' && $todo) {
            $this->em->remove($todo);
        } elseif ($action === 'clear') {
            foreach ($this->todos->findBy(['project' => $project, 'done' => true]) as $done) {
                $this->em->remove($done);
            }
        } else {
            throw new NotFoundHttpException();
        }
        $this->em->flush();
        return $this->redirect($this->generateUrl('studio_notes', ['slug' => $slug, 'note' => (string)$request->request->get('note') ?: null]) . '#taches');
    }

    private function add(Project $project, string $text): void
    {
        $count = $this->todos->count(['project' => $project]);
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $count++ < Todo::MAX_COUNT) {
                $this->em->persist(new Todo($project, mb_substr($line, 0, Todo::MAX_TEXT)));
            }
        }
    }

    private function checked(string $slug, Request $request, User $user): Project
    {
        $project = $this->owned($slug, $user);
        if (!$this->isCsrfTokenValid('studio-notes-' . $project->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        return $project;
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
