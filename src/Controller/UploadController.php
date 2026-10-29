<?php

namespace App\Controller;

use App\Message\ShootProject;
use App\Repository\ProjectRepository;
use App\Service\PrivateFiles;
use App\Service\ProjectException;
use App\Service\UploadToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Private files sent by the creator's Claude (MCP tool upload_private_files gives the URL), with curl:
 *   -F path=assets/data/LEVEL0.SQZ -F file=@LEVEL0.SQZ        one file
 *   -F path[]=... -F file[]=@... (repeated)                   several files
 *   -F folder=assets/data -F archive=@data.zip                 a zip archive, its paths kept under folder
 * No session, no cookie: the signed token of the URL names the project and its owner (UploadToken).
 */
final class UploadController extends AbstractController
{
    #[Route('/upload/{token}', name: 'upload_private', methods: ['POST'])]
    public function privateFiles(string $token, Request $request, UploadToken $tokens, ProjectRepository $projects, PrivateFiles $private, MessageBusInterface $bus): JsonResponse
    {
        $signed = $tokens->read($token);
        $project = $signed ? $projects->find($signed['project']) : null;
        if (!$project || $project->getOwner()->getId() !== $signed['user']) {
            return new JsonResponse(['error' => 'Invalid or expired upload URL: ask upload_private_files for a new one.'], 403);
        }
        $added = [];
        try {
            $archive = $request->files->get('archive');
            if ($archive !== null) {
                $private->addArchive($project, (string)$request->request->get('folder'), $this->checked($archive)->getPathname(), $added);
            }
            $files = $request->files->all()['file'] ?? [];
            $paths = $request->request->all()['path'] ?? [];
            foreach (is_array($files) ? $files : [$files] as $i => $file) {
                $path = is_array($paths) ? (string)($paths[$i] ?? '') : (string)$paths;
                if ($path === '') {
                    throw new ProjectException('Chemin refusé : %path%', ['%path%' => '(empty)']);
                }
                $added[] = $private->add($project, $path, $this->checked($file)->getPathname());
            }
        } catch (ProjectException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'added' => $added], 400);
        }
        if (!$added) {
            return new JsonResponse(['error' => 'Nothing sent: -F path=... -F file=@..., or -F folder=... -F archive=@x.zip'], 400);
        }
        // the game's picture was a screenshot: a new one (it may have shown a missing file)
        if ($project->isPublished() && (!$project->hasThumbnail() || $project->isShotThumbnail())) {
            $bus->dispatch(new ShootProject($project->getId(), true));
        }
        $index = $private->index($project);
        return new JsonResponse([
            'added' => $added,
            'files' => count($index),
            'size' => array_sum(array_column($index, 'size')),
        ]);
    }

    private function checked(mixed $file): UploadedFile
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new ProjectException('Fichier manquant ou trop gros (%max% Mo au plus).', ['%max%' => intdiv(PrivateFiles::MAX_FILE, 1048576)]);
        }
        return $file;
    }
}
