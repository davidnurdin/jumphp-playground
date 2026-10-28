<?php

namespace App\Service;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The picture of a game that has none (no thumbnail, no site picture, no icon): the shooter service (headless
 * Chrome, shooter/server.cjs) opens the game on the games origin, lets it run and photographs its screen. The
 * worker of the "shots" queue asks for one game at a time.
 */
final class Screenshots
{
    public function __construct(
        private HttpClientInterface $http,
        private GameFiles $files,
        private Thumbnailer $thumbnailer,
        private EntityManagerInterface $em,
        private string $playUrl,
        private string $shooterUrl,
    ) {
    }

    /** @return string what happened */
    public function shoot(Project $project, bool $force = false): string
    {
        $dir = $this->files->dir($project);
        if (!$dir || !$project->isPublished()) {
            return 'not published';
        }
        if ($project->hasThumbnail() && !$force) {
            return 'it has a picture';
        }
        $url = rtrim($this->playUrl, '/') . '/' . $project->getSlug() . '/play/';
        $file = tempnam(sys_get_temp_dir(), 'shot');
        try {
            // the title screen first; a screen of one colour (still loading, a black intro): further in the game
            foreach ([['seconds' => 5, 'keys' => ''], ['seconds' => 4, 'keys' => 'Enter,Enter']] as $try) {
                $response = $this->http->request('GET', rtrim($this->shooterUrl, '/') . '/shot', [
                    'query' => ['url' => $url] + $try,
                    'timeout' => 240,
                ]);
                if ($response->getStatusCode() !== 200) {
                    // the queue tries again a minute later (messenger.yaml)
                    throw new \RuntimeException('shooter: ' . substr($response->getContent(false), 0, 300));
                }
                file_put_contents($file, $response->getContent());
                if ($this->thumbnailer->colours($file) > 3) {
                    break;
                }
            }
            if (!$this->thumbnailer->fromScreen($file, "$dir/thumb.png")) {
                return 'not a picture';
            }
        } finally {
            @unlink($file);
        }
        $project->setHasThumbnail(true);
        $this->em->flush();
        return 'photographed';
    }
}
