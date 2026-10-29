<?php

namespace App\Tests;

use App\Entity\Category;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The playground end to end, without GitHub: a published game on disk, the portal, the games origin and its
 * isolation, the MCP server, the online editor's drafts and their preview.
 */
final class PlaygroundTest extends WebTestCase
{
    private const APP = 'jumphp.test';
    private const PLAY = 'play.jumphp.test';
    private const SHA = 'abcdef0123456789abcdef0123456789abcdef01';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $connection = $this->em->getConnection();
        foreach (['rating', 'draft', 'project', 'app_user'] as $table) {
            $connection->executeStatement("DELETE FROM $table");
        }
        $category = $this->em->getRepository(Category::class)->findOneBy(['slug' => 'arcade']);
        $this->user = new User(1001, 'tester');
        $this->user->setMcpToken(hash('sha256', 'jph_test'), 'test');
        $this->em->persist($this->user);
        $this->project = new Project($this->user, 'test-game', 'Test Game', $category, 'tester/test-game', 'games/test-game');
        $this->project->publish(self::SHA, '0.2.0', ['id' => 'test-game', 'title' => 'TEST GAME', 'width' => 320, 'height' => 180], null, false);
        $this->em->persist($this->project);
        $this->em->flush();

        $dir = $container->getParameter('app.storage_dir') . "/published/{$this->project->getId()}/" . self::SHA;
        $fs = new Filesystem();
        $fs->remove($container->getParameter('app.storage_dir'));
        $fs->dumpFile("$dir/game/src/Scene/PlayScene.php", "<?php\n// the game\n");
        $fs->dumpFile("$dir/game/config/game.yaml", "game:\n  id: test-game\n");
        $fs->dumpFile("$dir/manifest.json", json_encode(['format' => 1, 'engine' => '0.2.0', 'base' => 'files/', 'files' => [
            ['path' => 'config/game.yaml', 'hash' => substr(hash_file('sha256', "$dir/game/config/game.yaml"), 0, 16), 'size' => 25],
            ['path' => 'src/Scene/PlayScene.php', 'hash' => substr(hash_file('sha256', "$dir/game/src/Scene/PlayScene.php"), 0, 16), 'size' => 20],
        ]]));
    }

    private function get(string $host, string $path): void
    {
        $this->client->request('GET', "http://$host$path");
    }

    public function testPortalPages(): void
    {
        foreach (['/', '/categories', '/categorie/arcade', '/recherche?q=test', '/test-game', '/test-game/source', '/sitemap.xml', '/robots.txt'] as $path) {
            $this->get(self::APP, $path);
            self::assertResponseIsSuccessful($path);
        }
        $this->get(self::APP, '/');
        self::assertSelectorCount(1, 'a.card[href="/test-game"]');    // once, not again in its category
        $this->get(self::APP, '/test-game');
        self::assertSelectorTextContains('h1', 'Test Game');
        $this->get(self::APP, '/test-game/source/src/Scene/PlayScene.php');
        self::assertResponseIsSuccessful();
        // installable as an app of its own
        $this->get(self::APP, '/test-game/manifest.webmanifest');
        $manifest = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame(['/test-game', '/test-game/jouer?app=1', 'Test Game'], [$manifest['scope'], $manifest['start_url'], $manifest['short_name']]);
        $this->get(self::APP, $manifest['icons'][1]['src']);
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame([512, 512], array_slice(getimagesizefromstring((string)$this->client->getInternalResponse()->getContent()) ?: [], 0, 2));
        $this->get(self::APP, '/test-game/sw.js');
        self::assertResponseHeaderSame('Service-Worker-Allowed', '/test-game');
        $this->get(self::APP, '/nope-nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGamesOriginServesOnlyGames(): void
    {
        $this->get(self::PLAY, '/test-game/play/files/config/game.yaml');
        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('Set-Cookie');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        $this->get(self::PLAY, '/test-game/play/manifest.json');
        self::assertCount(2, json_decode((string)$this->client->getResponse()->getContent(), true)['files']);
        $this->get(self::PLAY, '/test-game/play/files/../manifest.json');
        self::assertResponseStatusCodeSame(404);
        $this->get(self::PLAY, '/test-game/raw/src/Scene/PlayScene.php');
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        // the portal's pages do not exist on the games origin, the games' files not on the portal
        foreach (['/studio', '/categories', '/mcp'] as $path) {
            $this->get(self::PLAY, $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->get(self::APP, '/test-game/play/manifest.json');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMcp(): void
    {
        $call = function (array $body, string $token = 'jph_test'): array {
            $this->client->request('POST', 'http://' . self::APP . '/mcp', [], [], [
                'HTTP_AUTHORIZATION' => "Bearer $token", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ], json_encode($body));
            return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
        };
        $init = $call(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']]]);
        self::assertSame('2025-06-18', $init['result']['protocolVersion']);
        $call(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertResponseStatusCodeSame(202);
        $tools = array_column($call(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'])['result']['tools'], null, 'name');
        self::assertSame(['original', 'cpc', 'port'], $tools['create_project']['inputSchema']['properties']['kind']['enum']);
        $who = $call(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => []]]);
        self::assertSame('tester', $who['result']['structuredContent']['login']);
        $file = $call(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'read_game_file', 'arguments' => ['slug' => 'test-game', 'path' => 'src/Scene/PlayScene.php']]]);
        self::assertStringContainsString('the game', $file['result']['structuredContent']['content']);
        $bad = $call(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'read_game_file', 'arguments' => ['slug' => 'test-game', 'path' => '../../manifest.json']]]);
        self::assertTrue($bad['result']['isError']);
        $call(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping'], 'jph_wrong');
        self::assertResponseStatusCodeSame(401);
    }

    public function testEditorDraftsAndPreview(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/test-game/edit');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#editor')->attr('data-csrf');
        $api = function (string $method, string $path, ?array $body = null) use ($csrf): array {
            $this->client->request($method, 'http://' . self::APP . '/api/projects/test-game' . $path, [], [], [
                'HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json',
            ], $body ? json_encode($body) : null);
            return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
        };
        self::assertSame('published', $api('GET', '/tree')['files'][0]['state']);
        $api('PUT', '/file', ['path' => 'src/Scene/PlayScene.php', 'content' => "<?php\n// changed\n"]);
        $api('PUT', '/file', ['path' => 'src/Entity/Hero.php', 'content' => "<?php\n// new\n"]);
        $api('DELETE', '/file?path=config/game.yaml');
        $states = array_column($api('GET', '/tree')['files'], 'state', 'path');
        self::assertSame(['config/game.yaml' => 'deleted', 'src/Entity/Hero.php' => 'added', 'src/Scene/PlayScene.php' => 'modified'], $states);
        self::assertStringContainsString('changed', $api('GET', '/file?path=src/Scene/PlayScene.php')['content']);

        // a new game: its kind (an Amstrad CPC remake is made with dsk2jumphp)
        $this->client->request('GET', 'http://' . self::APP . '/studio/nouveau');
        self::assertSelectorExists('select#kind option[value="cpc"]');
        self::assertSelectorTextContains('[data-kind-hint="cpc"]', 'dsk2jumphp');

        // the preview: the games origin gets the drafts through a signed token, without the session
        $url = $api('GET', '/preview')['url'];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->client->getCookieJar()->clear();
        $this->get(self::PLAY, '/test-game/play/manifest.json?draft=' . rawurlencode($query['draft']));
        $files = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        self::assertSame(['src/Entity/Hero.php', 'src/Scene/PlayScene.php'], array_keys($files));
        $this->get(self::PLAY, '/test-game/play/' . $files['src/Scene/PlayScene.php']['url']);
        self::assertStringContainsString('changed', (string)$this->client->getResponse()->getContent());
        $this->get(self::PLAY, '/test-game/play/manifest.json?draft=forged');
        self::assertCount(2, json_decode((string)$this->client->getResponse()->getContent(), true)['files']);
        self::assertArrayNotHasKey('url', json_decode((string)$this->client->getResponse()->getContent(), true)['files'][1]);

        // another user does not see these drafts, and a request without the CSRF token is refused
        $this->client->request('GET', 'http://' . self::APP . '/api/projects/test-game/tree');
        self::assertResponseStatusCodeSame(302);
        $this->client->loginUser($this->user);
        $this->client->request('GET', 'http://' . self::APP . '/api/projects/test-game/tree', [], [], ['HTTP_X_CSRF_TOKEN' => 'nope']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRatingsAndTheWallOfGames(): void
    {
        $rate = function (int $score, string $origin = 'http://jumphp.test'): array {
            $this->client->request('POST', 'http://' . self::APP . '/test-game/note', [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origin,
            ], json_encode(['score' => $score]));
            return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
        };
        // a visitor without an account: a cookie keeps their vote, voting again changes it
        self::assertEquals(['rating' => 4.0, 'count' => 1, 'mine' => 4], $rate(4));
        self::assertEquals(['rating' => 2.0, 'count' => 1, 'mine' => 2], $rate(2));
        // another browser, then a signed-in creator
        $this->client->getCookieJar()->clear();
        self::assertEquals(3, $rate(4)['rating']);
        $this->client->loginUser($this->user);
        self::assertEquals(['rating' => 3.3, 'count' => 3, 'mine' => 4], $rate(4));
        $rate(9);
        self::assertResponseStatusCodeSame(400);
        $rate(5, 'https://elsewhere.example');
        self::assertResponseStatusCodeSame(403);

        $this->get(self::APP, '/test-game');
        self::assertSelectorExists('[data-rate] button.on[data-score="4"]');
        foreach (['', '?tri=nom', '?tri=date', '?tri=parties', '?tri=n-importe'] as $query) {
            $this->get(self::APP, '/games' . $query);
            self::assertResponseIsSuccessful($query);
            self::assertSelectorTextContains('.wall .tile', 'Test Game');
        }
        self::assertSelectorExists('.wall-top a.logo[href="/"]');
        $this->get(self::APP, '/jeux');
        self::assertResponseRedirects('/games', 301);
    }

    public function testPrivateFiles(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/studio/projet/test-game');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-private] [name=_token]')->attr('value');
        $data = tempnam(sys_get_temp_dir(), 'sqz');
        file_put_contents($data, 'original game data');
        $this->client->request('POST', 'http://' . self::APP . '/studio/projet/test-game/prives', ['_token' => $token, 'path' => 'assets/data/LEVEL0.SQZ'], [
            'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($data, 'LEVEL0.SQZ', null, null, true),
        ]);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', 'http://' . self::APP . '/studio/projet/test-game/prives', ['_token' => $token, 'path' => '../escape'], [
            'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($data, 'x', null, null, true),
        ]);
        self::assertResponseStatusCodeSame(400);

        // the game loads it like its own files; the sources and the raw files do not show it
        $this->get(self::PLAY, '/test-game/play/manifest.json');
        $files = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        self::assertStringStartsWith('private/assets/data/LEVEL0.SQZ?v=', $files['assets/data/LEVEL0.SQZ']['url']);
        $this->get(self::PLAY, '/test-game/play/' . $files['assets/data/LEVEL0.SQZ']['url']);
        self::assertSame('original game data', $this->client->getInternalResponse()->getContent());
        $this->get(self::PLAY, '/test-game/raw/assets/data/LEVEL0.SQZ');
        self::assertResponseStatusCodeSame(404);
        $this->get(self::APP, '/test-game/source/assets/data/LEVEL0.SQZ');
        self::assertResponseStatusCodeSame(404);

        // removed from the Studio
        $this->client->loginUser($this->user);
        $this->client->request('POST', 'http://' . self::APP . '/studio/projet/test-game/prives/fin', ['_token' => $token, 'remove' => ['assets/data/LEVEL0.SQZ']]);
        self::assertResponseRedirects();
        $this->get(self::PLAY, '/test-game/play/manifest.json');
        self::assertCount(2, json_decode((string)$this->client->getResponse()->getContent(), true)['files']);
    }
}
