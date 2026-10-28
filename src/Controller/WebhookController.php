<?php

namespace App\Controller;

use App\Message\SyncProject;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/** GitHub's push events (the webhook made with each project): the game is published again. */
final class WebhookController extends AbstractController
{
    #[Route('/webhook/github/{id<\d+>}', name: 'webhook_github', methods: ['POST'])]
    public function github(int $id, Request $request, ProjectRepository $projects, MessageBusInterface $bus): JsonResponse
    {
        $project = $projects->find($id);
        $signature = (string)$request->headers->get('X-Hub-Signature-256');
        $body = $request->getContent();
        if (!$project || !hash_equals('sha256=' . hash_hmac('sha256', $body, $project->getWebhookSecret()), $signature)) {
            return new JsonResponse(['error' => 'bad signature'], 403);
        }
        $event = $request->headers->get('X-GitHub-Event');
        if ($event === 'ping') {
            return new JsonResponse(['ok' => true]);
        }
        $payload = json_decode($body, true) ?: [];
        if ($event === 'push' && ($payload['ref'] ?? '') === 'refs/heads/' . $project->getDefaultBranch()) {
            $bus->dispatch(new SyncProject($project->getId()));
            return new JsonResponse(['queued' => true], 202);
        }
        return new JsonResponse(['ignored' => true]);
    }
}
