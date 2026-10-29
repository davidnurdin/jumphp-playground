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
        foreach (['test_run', 'comment', 'note', 'todo', 'rating', 'draft', 'project', 'app_user'] as $table) {
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

        // private files: a signed URL for curl (one file, a zip archive), listed, removed
        $tool = fn (string $name, array $arguments) => $call(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]])['result'];
        $url = $tool('upload_private_files', ['slug' => 'test-game'])['structuredContent']['url'];
        $data = tempnam(sys_get_temp_dir(), 'sqz');
        file_put_contents($data, 'original game data');
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', $url, ['path' => 'assets/data/LEVEL0.SQZ'], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($data, 'LEVEL0.SQZ', null, null, true)]);
        self::assertResponseIsSuccessful();
        $zipFile = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::OVERWRITE);
        $zip->addFromString('music/theme.ogg', 'ogg');
        $zip->addFromString('../escape', 'no');
        $zip->close();
        $this->client->request('POST', $url, ['folder' => 'assets'], ['archive' => new \Symfony\Component\HttpFoundation\File\UploadedFile($zipFile, 'data.zip', null, null, true)]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(['assets/music/theme.ogg'], json_decode((string)$this->client->getResponse()->getContent(), true)['added']);
        $this->client->request('POST', $url . 'x', ['path' => 'a'], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($data, 'a', null, null, true)]);
        self::assertResponseStatusCodeSame(403);
        $listed = $tool('list_private_files', ['slug' => 'test-game'])['structuredContent'];
        self::assertSame(['assets/data/LEVEL0.SQZ', 'assets/music/theme.ogg'], array_column($listed['files'], 'path'));
        self::assertTrue($tool('remove_private_files', ['slug' => 'test-game', 'paths' => ['nope']])['isError']);
        $left = $tool('remove_private_files', ['slug' => 'test-game', 'paths' => ['assets/music/theme.ogg']])['structuredContent'];
        self::assertSame(['assets/data/LEVEL0.SQZ'], array_column($left['files'], 'path'));
        self::assertSame(0, $tool('remove_private_files', ['slug' => 'test-game', 'all' => true])['structuredContent']['count']);

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

    public function testCommentsIdeasIssuesAndProfiles(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $post = function (string $path, array $fields, string $origin = 'http://jumphp.test'): void {
            $this->client->request('POST', 'http://' . self::APP . $path, $fields, [], ['HTTP_ORIGIN' => $origin]);
        };
        // an anonymous comment waits: its writer sees it, not another visitor
        $post('/test-game/commentaires', ['body' => 'Super jeu !', 'name' => 'Zoé', 'from' => 'page']);
        self::assertResponseRedirects('/test-game#retours');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.comment.pending', 'Super jeu !');
        $this->client->getCookieJar()->clear();
        $this->get(self::APP, '/test-game');
        self::assertSelectorNotExists('.comment');
        // the same for the idea box and issues, on their own pages
        $post('/test-game/idees', ['body' => 'Un mode deux joueurs', 'from' => 'list']);
        self::assertResponseRedirects('/test-game/idees#retours');
        $post('/test-game/problemes', ['body' => 'Le son coupe au niveau 2']);
        self::assertResponseRedirects('/test-game?retours=problemes#retours');
        $this->client->getCookieJar()->clear();
        $this->get(self::APP, '/test-game/idees');
        self::assertSelectorNotExists('.comment');
        // robots, other sites, empty or too long messages
        $post('/test-game/commentaires', ['body' => 'spam', 'website' => 'http://spam.example']);
        $post('/test-game/commentaires', ['body' => 'hi'], 'https://elsewhere.example');
        self::assertResponseStatusCodeSame(403);
        $post('/test-game/commentaires', ['body' => '  ']);
        self::assertResponseStatusCodeSame(422);
        $post('/test-game/commentaires', ['body' => str_repeat('a', 2001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM comment'));

        // the owner sees them all, approves the comment and the issue, resolves the issue
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/test-game');
        self::assertSelectorTextContains('.comment.pending', 'Super jeu !');
        $form = $this->client->getCrawler()->filter('.comment form')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/test-game#retours');
        $this->get(self::APP, '/test-game/problemes');
        $this->client->submit($this->client->getCrawler()->selectButton('✓ Valider')->form());
        $this->get(self::APP, '/test-game/problemes');
        $this->client->submit($this->client->getCrawler()->selectButton('Marquer résolu')->form());
        // a signed-in user's comment is shown at once, with their picture and a link to their profile
        $other = new User(1002, 'Player2');
        $other->setAvatarUrl('https://avatars.githubusercontent.com/u/1002?v=4');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);
        $post('/test-game/commentaires', ['body' => 'Bravo']);
        $this->client->getCookieJar()->clear();
        $this->get(self::APP, '/test-game');
        self::assertSelectorCount(2, '.comment');
        self::assertSelectorExists('.comment a.avatar[href="/dev/Player2"] img');
        self::assertSelectorNotExists('.comment.pending');
        self::assertSelectorTextContains('.tabs a.on', '2');
        $this->get(self::APP, '/test-game/problemes');
        self::assertSelectorTextContains('.comment', 'résolu');
        $this->get(self::APP, '/test-game/idees');
        self::assertSelectorNotExists('.comment');
        // another user cannot remove a comment that is not theirs
        $this->client->loginUser($other);
        $this->get(self::APP, '/test-game');
        self::assertSelectorCount(1, '.comment form');

        // the developers' pages
        $this->get(self::APP, '/dev/tester');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'tester');
        self::assertSelectorExists('a.card[href="/test-game"]');
        self::assertSelectorTextContains('.two', 'Bravo');
        $this->get(self::APP, '/dev/player2');
        self::assertResponseRedirects('/dev/Player2', 301);
        $this->get(self::APP, '/dev/nobody');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMcpNotesAndTodos(): void
    {
        $tool = function (string $name, array $arguments): array {
            $this->client->request('POST', 'http://' . self::APP . '/mcp', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer jph_test', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
            ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]]));
            return json_decode((string)$this->client->getResponse()->getContent(), true)['result'];
        };
        $tool('save_note', ['slug' => 'test-game', 'content' => "# State\nlevel 1 done"]);
        $tool('save_note', ['slug' => 'test-game', 'content' => 'level 2 started', 'append' => true]);
        $tool('save_note', ['slug' => 'test-game', 'name' => 'ideas', 'content' => 'a boss']);
        $notes = $tool('get_notes', ['slug' => 'test-game'])['structuredContent'];
        self::assertSame("# State\nlevel 1 done\n\nlevel 2 started", $notes['content']);
        self::assertSame(['ideas', 'progress'], array_column($notes['notes'], 'name'));
        self::assertSame('a boss', $tool('get_notes', ['slug' => 'test-game', 'name' => 'ideas'])['structuredContent']['content']);

        $todos = $tool('add_todos', ['slug' => 'test-game', 'items' => ['sound', 'menu', 'boss']])['structuredContent']['todos'];
        [$sound, $menu, $boss] = array_column($todos, 'id');
        $left = $tool('update_todos', ['slug' => 'test-game', 'done' => [$sound], 'remove' => [$boss], 'text' => [(string)$menu => 'pause menu']])['structuredContent'];
        self::assertSame([['id' => $menu, 'text' => 'pause menu', 'done' => false]], $left['todos']);
        self::assertSame(2, count($tool('list_todos', ['slug' => 'test-game', 'all' => true])['structuredContent']['todos']));

        // private: the studio shows them to the owner only, and players' feedback is there for their Claude
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/studio/projet/test-game');
        self::assertSelectorTextContains('.todo-list', 'pause menu');
        self::assertSelectorTextContains('details.note[open]', 'level 2 started');
        $this->client->request('POST', 'http://' . self::APP . '/test-game/problemes', ['body' => 'crash'], [], ['HTTP_ORIGIN' => 'http://jumphp.test']);
        $issue = $tool('list_feedback', ['slug' => 'test-game'])['structuredContent']['issue'][0];
        self::assertSame(['crash', true], [$issue['text'], $issue['approved']]);
        $tool('moderate_feedback', ['slug' => 'test-game', 'id' => $issue['id'], 'action' => 'resolve']);
        self::assertTrue($tool('list_feedback', ['slug' => 'test-game', 'section' => 'issue'])['structuredContent']['issue'][0]['resolved']);
        self::assertTrue($tool('get_notes', ['slug' => 'nope'])['isError']);
    }

    public function testPhpunitRunsInTheTester(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/studio/projet/test-game');
        self::assertAnySelectorTextContains('.panel', 'Pas encore de tests');
        $storage = static::getContainer()->getParameter('app.storage_dir');
        $testsDir = static::getContainer()->getParameter('app.tests_dir');
        (new Filesystem())->remove($testsDir);
        (new Filesystem())->dumpFile("$storage/published/{$this->project->getId()}/" . self::SHA . '/game/tests/ScoreTest.php', "<?php\n");
        // a game test plays the game (SDL): left out of the sandbox, and said
        (new Filesystem())->dumpFile("$storage/published/{$this->project->getId()}/" . self::SHA . '/game/tests/PaddleTest.php', "<?php\nfinal class PaddleTest extends \\JumPHP\\Testing\\GameTestCase {}\n");
        $this->get(self::APP, '/studio/projet/test-game');
        $this->client->submit($this->client->getCrawler()->selectButton('▶ Lancer les tests')->form());
        self::assertResponseRedirects();
        $id = (int)basename((string)$this->client->getResponse()->headers->get('Location'));
        // what the tester gets: the game, the engine and an autoloader of both
        self::assertFileExists("$testsDir/jobs/$id.json");
        self::assertFileExists("$testsDir/work/$id/tests/ScoreTest.php");
        self::assertFileDoesNotExist("$testsDir/work/$id/tests/PaddleTest.php");
        self::assertFileExists("$testsDir/work/$id/vendor/autoload_engine.php");
        self::assertStringContainsString("'App\\\\'", (string)file_get_contents("$testsDir/work/$id/vendor/autoload.php"));
        $this->client->followRedirect();
        self::assertSelectorExists('meta[http-equiv="refresh"]');
        // what it found
        file_put_contents("$testsDir/results/$id.json", json_encode(['status' => 'failed', 'output' => 'FAILURES!', 'summary' => [
            'tests' => 2, 'assertions' => 2, 'failures' => 1, 'errors' => 0, 'skipped' => 0, 'time' => 0.01, 'cases' => [
                ['class' => 'Tests\\ScoreTest', 'name' => 'testAdds', 'status' => 'passed', 'message' => null, 'time' => 0.0],
                ['class' => 'Tests\\ScoreTest', 'name' => 'testBonus', 'status' => 'failed', 'message' => 'Failed asserting that 3 is identical to 4.', 'time' => 0.0],
            ]]]));
        $this->get(self::APP, "/studio/projet/test-game/tests/$id");
        self::assertSelectorNotExists('meta[http-equiv="refresh"]');
        self::assertSelectorTextContains('.test-cases li.failed', 'Failed asserting that 3 is identical to 4.');
        self::assertSelectorTextContains('pre.test-output', 'FAILURES!');
        self::assertSelectorTextContains('pre.test-output', '1 fichier(s) de tests de jeu');
        self::assertFileDoesNotExist("$testsDir/work/$id");
        // the owner's Claude sees the same; nobody else sees the page
        $this->client->request('POST', 'http://' . self::APP . '/mcp', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer jph_test', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_test_run', 'arguments' => ['slug' => 'test-game']]]));
        $run = json_decode((string)$this->client->getResponse()->getContent(), true)['result']['structuredContent'];
        self::assertSame(['failed', 2, 1], [$run['status'], $run['tests'], $run['failures']]);
        $other = new User(1003, 'other');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);
        $this->get(self::APP, "/studio/projet/test-game/tests/$id");
        self::assertResponseStatusCodeSame(404);
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

    public function testSandboxWithoutAccount(): void
    {
        $this->get(self::APP, '/bac-a-sable');
        self::assertResponseRedirects('/bac-a-sable/hello-world');
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/bac-a-sable/test-game');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#btn-push');
        $api = $crawler->filter('#editor')->attr('data-api');
        $token = $crawler->filter('#editor')->attr('data-csrf');
        $call = function (string $method, string $path, ?array $body = null, ?string $csrf = null) use ($api, $token): array {
            $this->client->request($method, 'http://' . self::APP . $api . $path, [], [], [
                'HTTP_X_CSRF_TOKEN' => $csrf ?? $token, 'CONTENT_TYPE' => 'application/json',
            ], $body ? json_encode($body) : null);
            return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
        };
        self::assertSame('added', $call('PUT', '/file', ['path' => 'src/Mine.php', 'content' => "<?php\n// mine\n"])['state']);
        $call('GET', '/tree', null, 'forged');
        self::assertResponseStatusCodeSame(403);

        // played with the visitor's files, served as bytes only
        parse_str((string)parse_url($call('GET', '/preview')['url'], PHP_URL_QUERY), $query);
        $this->get(self::PLAY, '/test-game/play/manifest.json?draft=' . rawurlencode($query['draft']));
        $files = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        $this->get(self::PLAY, '/test-game/play/' . $files['src/Mine.php']['url']);
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertStringContainsString('sandbox', (string)$this->client->getResponse()->headers->get('Content-Security-Policy'));

        // another browser has its own sandbox; a visitor cannot push
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/bac-a-sable/test-game');
        $other = $crawler->filter('#editor')->attr('data-csrf');
        self::assertNotSame($token, $other);
        $states = array_column($call('GET', '/tree', null, $other)['files'], 'state', 'path');
        self::assertArrayNotHasKey('src/Mine.php', $states);
        $this->client->request('POST', 'http://' . self::APP . str_replace('/tree', '/push', $api), [], [], ['HTTP_X_CSRF_TOKEN' => $other]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublicApi(): void
    {
        $json = function (string $path, string $accept = 'application/json'): mixed {
            $this->client->request('GET', 'http://' . self::APP . $path, [], [], ['HTTP_ACCEPT' => $accept, 'HTTP_ORIGIN' => 'https://elsewhere.example']);
            return json_decode((string)$this->client->getResponse()->getContent(), true);
        };
        $hidden = new Project($this->user, 'hidden-game', 'Hidden', $this->project->getCategory(), 'tester/hidden', 'games/hidden');
        $hidden->publish(self::SHA, '0.2.0', [], null, false);
        $hidden->setListed(false);
        $this->em->persist($hidden);
        $this->em->flush();

        $games = $json('/api/games?order[title]=asc');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'https://elsewhere.example');
        self::assertSame(['test-game'], array_column($games, 'slug'));
        self::assertSame(['category' => 'arcade', 'kind' => 'original', 'author' => 'tester', 'rating' => null, 'ratings' => 0],
            array_intersect_key($games[0], array_flip(['category', 'kind', 'author', 'rating', 'ratings'])));
        self::assertSame('http://jumphp.test/test-game/jouer', $games[0]['play']);
        self::assertStringEndsWith('/test-game/play/manifest.json', $games[0]['manifest']);
        self::assertSame([], $json('/api/games?kind=cpc'));
        self::assertSame([], $json('/api/games?category=tir'));
        $json('/api/games?kind=nope');
        self::assertResponseStatusCodeSame(422);

        $page = $json('/api/games', 'application/ld+json');
        self::assertSame(1, $page['totalItems']);
        self::assertSame('/api/games/test-game', $page['member'][0]['@id']);
        self::assertSame('test-game', $json('/api/games/test-game')['slug']);
        $json('/api/games/hidden-game');
        self::assertResponseStatusCodeSame(404);

        $arcade = $json('/api/categories/arcade');
        self::assertSame(1, $arcade['games']);
        self::assertContains('arcade', array_column($json('/api/categories'), 'slug'));
        $this->client->request('GET', 'http://' . self::APP . '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('/api/games', json_decode((string)$this->client->getResponse()->getContent(), true)['paths']);

        // the public API answers on the portal only, and the editor's API stays private
        $this->client->request('GET', 'http://' . self::PLAY . '/api/games');
        self::assertResponseStatusCodeSame(404);
        $json('/api/bac-a-sable/test-game/tree');
        self::assertResponseStatusCodeSame(403);
        self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }

    public function testDocs(): void
    {
        foreach (\App\Controller\DocsController::PAGES as $pages) {
            foreach (array_keys($pages) as $page) {
                $this->get(self::APP, $page === 'index' ? '/docs' : "/docs/$page");
                self::assertResponseIsSuccessful($page);
                self::assertSelectorExists('article.doc h1');
            }
        }
        $this->get(self::APP, '/docs/nope');
        self::assertResponseStatusCodeSame(404);
    }
}
