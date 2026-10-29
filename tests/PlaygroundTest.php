<?php

namespace App\Tests;

use App\Entity\Category;
use App\Entity\Comment;
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
        foreach (['test_run', 'sandbox_share', 'comment', 'note', 'todo', 'rating', 'draft', 'project', 'app_user'] as $table) {
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
        self::assertStringNotContainsString('&amp;#', (string)$this->client->getResponse()->getContent());
        $this->get(self::APP, '/test-game/source/src/Scene/PlayScene.php');
        self::assertResponseIsSuccessful();
        // installable as an app of its own
        $this->get(self::APP, '/test-game/manifest.webmanifest');
        $manifest = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame(['/test-game', '/test-game/jouer?app=1', 'Test Game'], [$manifest['scope'], $manifest['start_url'], $manifest['short_name']]);
        $this->get(self::APP, $manifest['icons'][1]['src']);
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame([512, 512], array_slice(getimagesizefromstring((string)$this->client->getInternalResponse()->getContent()) ?: [], 0, 2));
        // a long title: its name under the icon without its article; a short name in game.yaml wins
        $this->project->setTitle("L'Armure Sacrée d'Antiriad");
        self::assertSame("Armure Sacrée d'Antiriad", $this->project->getAppName());
        $this->project->publish((string)$this->project->getPublishedSha(), (string)$this->project->getEngineVersion(), ['short_name' => 'Antiriad'] + $this->project->getGameInfo(), $this->project->getSite(), $this->project->hasThumbnail());
        self::assertSame('Antiriad', $this->project->getAppName());
        // the site itself is an app too
        $this->get(self::APP, '/manifest.webmanifest');
        self::assertSame(['JumPHP', '/'], array_values(array_intersect_key((array)json_decode((string)$this->client->getResponse()->getContent(), true), ['scope' => 1, 'short_name' => 1])));
        $this->get(self::APP, '/sw.js');
        self::assertResponseHeaderSame('Service-Worker-Allowed', '/');
        $this->get(self::APP, '/test-game/sw.js');
        self::assertResponseHeaderSame('Service-Worker-Allowed', '/test-game');
        // JumPHP's installers in one line
        $this->get(self::APP, '/install.sh');
        self::assertResponseRedirects('https://raw.githubusercontent.com/davidnurdin/jumPhp/main/install.sh');
        $this->get(self::APP, '/install.ps1');
        self::assertResponseRedirects('https://raw.githubusercontent.com/davidnurdin/jumPhp/main/install.ps1');
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
        self::assertSelectorExists('header.top nav a.on[href="/games"]');    // the whole menu, on its Games tab
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
        // a game test plays the game (SDL, in the sandbox too): kept, and filmed
        (new Filesystem())->dumpFile("$storage/published/{$this->project->getId()}/" . self::SHA . '/game/tests/PaddleTest.php', "<?php\nfinal class PaddleTest extends \\JumPHP\\Testing\\GameTestCase {}\n");
        $this->get(self::APP, '/studio/projet/test-game');
        $this->client->submit($this->client->getCrawler()->selectButton('▶ Lancer les tests')->form());
        self::assertResponseRedirects();
        $id = (int)basename((string)$this->client->getResponse()->headers->get('Location'));
        // what the tester gets: the game, the engine and an autoloader of both
        self::assertFileExists("$testsDir/jobs/$id.json");
        self::assertFileExists("$testsDir/work/$id/tests/ScoreTest.php");
        self::assertFileExists("$testsDir/work/$id/tests/PaddleTest.php");
        self::assertFileExists("$testsDir/work/$id/vendor/autoload_engine.php");
        self::assertStringContainsString("'App\\\\'", (string)file_get_contents("$testsDir/work/$id/vendor/autoload.php"));
        $this->client->followRedirect();
        self::assertSelectorExists('[data-films][data-done="0"]');
        // the tester films the game tests as they run: the page asks for them
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        (new Filesystem())->dumpFile("$testsDir/films/$id/001/0001.png", $png);
        (new Filesystem())->dumpFile("$testsDir/films/$id/index.json", json_encode(['tests' => [['n' => '001', 'name' => 'Tests\\PaddleTest::testThePaddleGoesDown', 'frames' => 1]]]));
        $this->get(self::APP, "/studio/projet/test-game/tests/$id/etat");
        $state = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame([false, 'The paddle goes down', 'run'], [$state['done'], $state['films'][0]['label'], $state['films'][0]['status']]);
        $this->get(self::APP, "/studio/projet/test-game/tests/$id/film/001/0001.png");
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        // what it found
        file_put_contents("$testsDir/results/$id.json", json_encode(['status' => 'failed', 'output' => 'FAILURES!', 'summary' => [
            'tests' => 2, 'assertions' => 2, 'failures' => 1, 'errors' => 0, 'skipped' => 0, 'time' => 0.01, 'cases' => [
                ['class' => 'Tests\\ScoreTest', 'name' => 'testAdds', 'status' => 'passed', 'message' => null, 'time' => 0.0],
                ['class' => 'Tests\\ScoreTest', 'name' => 'testBonus', 'status' => 'failed', 'message' => 'Failed asserting that 3 is identical to 4.', 'time' => 0.0],
                ['class' => 'Tests\\PaddleTest', 'name' => 'testThePaddleGoesDown', 'status' => 'passed', 'message' => null, 'time' => 0.5],
            ]]]));
        $this->get(self::APP, "/studio/projet/test-game/tests/$id");
        self::assertSelectorExists('[data-films][data-done="1"]');
        self::assertStringContainsString('"status":"passed"', (string)$this->client->getCrawler()->filter('[data-films]')->attr('data-initial'));
        self::assertSelectorTextContains('.test-cases li.failed', 'Failed asserting that 3 is identical to 4.');
        self::assertSelectorTextContains('pre.test-output', 'FAILURES!');
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

    public function testSandboxSharedByALink(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/bac-a-sable/test-game');
        $api = 'http://' . self::APP . $crawler->filter('#editor')->attr('data-api');
        $token = $crawler->filter('#editor')->attr('data-csrf');
        $call = function (string $method, string $path, ?array $body = null) use ($api, $token): array {
            $this->client->request($method, $api . $path, [], [], ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], $body ? json_encode($body) : null);
            return json_decode((string)$this->client->getResponse()->getContent(), true) ?? [];
        };
        // nothing changed: nothing to share
        $call('POST', '/share', ['duration' => 'week']);
        self::assertResponseStatusCodeSame(400);
        $call('PUT', '/file', ['path' => 'src/Mine.php', 'content' => "<?php\n// v1\n"]);
        $call('POST', '/share', ['duration' => 'never']);
        self::assertResponseStatusCodeSame(400);
        $share = $call('POST', '/share', ['duration' => 'week', 'title' => 'Ma démo']);
        self::assertMatchesRegularExpression('#/bac-a-sable/partage/[A-Za-z0-9]{10}$#', $share['url']);
        self::assertNotNull($share['expires']);
        // the link is frozen: later changes are not in it
        $call('PUT', '/file', ['path' => 'src/Mine.php', 'content' => "<?php\n// v2\n"]);

        // someone else opens it: plays it, continues it in their sandbox
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $share['url']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ma démo');
        self::assertSelectorTextContains('.shared-files', 'src/Mine.php');
        parse_str((string)parse_url((string)$crawler->filter('.shared-play iframe')->attr('src'), PHP_URL_QUERY), $query);
        $this->get(self::PLAY, '/test-game/play/manifest.json?draft=' . rawurlencode($query['draft']));
        $files = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        $this->get(self::PLAY, '/test-game/play/' . $files['src/Mine.php']['url']);
        self::assertStringContainsString('v1', (string)$this->client->getResponse()->getContent());
        $this->client->submit($crawler->selectButton('✏️ Continuer dans mon bac à sable')->form());
        self::assertResponseRedirects('/bac-a-sable/test-game');
        $crawler = $this->client->followRedirect();
        $this->client->request('GET', 'http://' . self::APP . $crawler->filter('#editor')->attr('data-api') . '/file?path=src/Mine.php', [], [], ['HTTP_X_CSRF_TOKEN' => $crawler->filter('#editor')->attr('data-csrf')]);
        self::assertStringContainsString('v1', json_decode((string)$this->client->getResponse()->getContent(), true)['content']);

        // signed in, into their space: their drafts of the game (the online editor, the studio)
        $other = new User(1004, 'friend');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', $share['url']);
        $this->client->submit($crawler->selectButton('📥 Importer dans mon espace')->form());
        self::assertResponseRedirects('/test-game/edit');
        $this->get(self::APP, '/studio');
        self::assertSelectorTextContains('body', 'En cours dans l\'éditeur');
        self::assertSelectorTextContains('body', '1 fichier(s) modifié(s)');
        // the sandbox of a signed-in user, imported the same way
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/bac-a-sable/test-game');
        $this->client->submit($crawler->filter('form[data-flush]')->form());
        self::assertResponseRedirects('/test-game/edit');

        // the sandboxes are forgotten after a while, not the links; an expired link is gone with its files
        $this->em->getConnection()->executeStatement("UPDATE draft SET updated_at = '2020-01-01' WHERE visitor IS NOT NULL");
        $this->get(self::APP, '/bac-a-sable/test-game');
        $this->get(self::APP, parse_url($share['url'], PHP_URL_PATH));
        self::assertResponseIsSuccessful();
        $this->em->getConnection()->executeStatement("UPDATE sandbox_share SET expires_at = '2020-01-01'");
        $this->get(self::APP, parse_url($share['url'], PHP_URL_PATH));
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM sandbox_share'));
        self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM draft WHERE visitor IS NOT NULL'));
    }

    public function testNotifications(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->client->request('POST', 'http://' . self::APP . '/test-game/idees', ['body' => 'Un mode deux joueurs', 'name' => 'Zoé'], [], ['HTTP_ORIGIN' => 'http://jumphp.test']);
        $this->client->request('POST', 'http://' . self::APP . '/test-game/problemes', ['body' => 'Le son coupe'], [], ['HTTP_ORIGIN' => 'http://jumphp.test']);
        // the owner's bell: 2; their page lists them, the anonymous ones to approve
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/studio');
        self::assertSelectorTextContains('.bell .bell-count', '2');
        $crawler = $this->client->request('GET', 'http://' . self::APP . '/studio/notifications?filtre=attente');
        self::assertCount(2, $crawler->filter('.notes-list > li.pending.new'));
        self::assertSelectorTextContains('.notes-list', 'Un mode deux joueurs');
        $this->client->submit($crawler->filter('.notes-list > li')->last()->selectButton('✓ Valider')->form());
        self::assertResponseRedirects('/studio/notifications?filtre=attente');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('.notes-list > li'));
        // seen: only the one still waiting counts
        self::assertSelectorTextContains('.bell .bell-count', '1');
        $this->get(self::APP, '/studio/notifications');
        self::assertCount(2, $this->client->getCrawler()->filter('.notes-list > li'));
        self::assertCount(0, $this->client->getCrawler()->filter('.notes-list > li.new'));
        // nobody else's
        $other = new User(1005, 'other');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);
        $this->get(self::APP, '/studio/notifications');
        self::assertSelectorNotExists('.notes-list');
        self::assertSelectorNotExists('.bell .bell-count');
    }

    public function testListsByPages(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        for ($i = 1; $i <= 25; $i++) {
            $this->em->persist(new Comment($this->project, "message $i", $this->user));
        }
        $this->em->flush();
        $this->get(self::APP, '/test-game/commentaires');
        self::assertCount(20, $this->client->getCrawler()->filter('.comment-list > li'));
        self::assertSelectorExists('.pager a[rel=next][href="/test-game/commentaires?page=2#retours"]');
        $this->get(self::APP, '/test-game/commentaires?page=2');
        self::assertCount(5, $this->client->getCrawler()->filter('.comment-list > li'));
        self::assertSelectorTextContains('.pager', 'Page 2 / 2');
        // under the game: the first ones, and a link to the others
        $this->get(self::APP, '/test-game');
        self::assertSelectorTextContains('#retours', 'Voir les suivants (25 en tout)');
        // a page past the end is the last one
        $this->get(self::APP, '/test-game/commentaires?page=99');
        self::assertSelectorTextContains('.pager', 'Page 2 / 2');
    }

    public function testStatisticsOfAGame(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0');
        $this->get(self::APP, '/test-game');
        $this->get(self::APP, '/test-game/jouer');
        $this->get(self::APP, '/test-game/jouer');
        $this->get(self::APP, '/test-game/jouer?app=1');
        // a robot is not counted, nor the creator
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Googlebot/2.1');
        $this->get(self::APP, '/test-game/jouer');
        $this->client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 Firefox/140.0');
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/test-game/jouer');
        $this->get(self::APP, '/studio');
        self::assertSelectorExists('a[href="/studio/projet/test-game/statistiques"]');
        $this->get(self::APP, '/studio/projet/test-game/statistiques');
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2', '1'], $this->client->getCrawler()->filter('.stat-tile b')->slice(0, 3)->each(fn ($b) => $b->text()));
        self::assertSelectorCount(3 * 30, '.bars .bar');    // three charts of 30 days
        // only its creator sees them
        $someone = new User(1003, 'someone');
        $this->em->persist($someone);
        $this->em->flush();
        $this->client->loginUser($someone);
        $this->get(self::APP, '/studio/projet/test-game/statistiques');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAndroidAppOfAGame(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->get(self::APP, '/.well-known/assetlinks.json');
        self::assertSame([], json_decode((string)$this->client->getResponse()->getContent(), true));
        // the creator declares the app in the studio: its package, the fingerprint of its key (any case, colons or not)
        $this->client->loginUser($this->user);
        $this->get(self::APP, '/studio/projet/test-game');
        $this->client->submit($this->client->getCrawler()->filter('#android')->form([
            'package' => 'io.github.tester.test_game',
            'fingerprints' => strtolower(str_repeat('ab', 32)) . "\n",
        ]));
        $this->get(self::APP, '/.well-known/assetlinks.json');
        $links = json_decode((string)$this->client->getResponse()->getContent(), true);
        self::assertSame(['delegate_permission/common.handle_all_urls'], $links[0]['relation']);
        self::assertSame(['namespace' => 'android_app', 'package_name' => 'io.github.tester.test_game', 'sha256_cert_fingerprints' => [implode(':', array_fill(0, 32, 'AB'))]], $links[0]['target']);
        // a wrong package is refused and said
        $this->get(self::APP, '/studio/projet/test-game');
        $this->client->submit($this->client->getCrawler()->filter('#android')->form(['package' => 'Not A Package']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'paquet Android invalide');
        // or by the creator's Claude (MCP): Google Play's key added
        $this->client->request('POST', 'http://' . self::APP . '/mcp', [], [], ['HTTP_AUTHORIZATION' => 'Bearer jph_test', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'update_project', 'arguments' => [
                'slug' => 'test-game', 'android_package' => 'io.github.tester.test_game', 'android_fingerprints' => [str_repeat('AB', 32), str_repeat('CD', 32)]]]]));
        $result = json_decode((string)$this->client->getResponse()->getContent(), true)['result'];
        self::assertCount(2, $result['structuredContent']['android']['fingerprints']);
        $this->get(self::APP, '/.well-known/assetlinks.json');
        self::assertCount(2, json_decode((string)$this->client->getResponse()->getContent(), true)[0]['target']['sha256_cert_fingerprints']);
    }

    public function testOlderVersionsPlayedAndEdited(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        // the mirror of the game's repository: two commits that changed the game, one that did not
        $git = sys_get_temp_dir() . '/jumphp-versions-' . bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $run = function (string ...$command) use ($git): void {
            $process = new \Symfony\Component\Process\Process($command, $git, ['GIT_AUTHOR_NAME' => 'tester', 'GIT_AUTHOR_EMAIL' => 'tester@example.test',
                'GIT_COMMITTER_NAME' => 'tester', 'GIT_COMMITTER_EMAIL' => 'tester@example.test']);
            $process->mustRun();
        };
        $fs->dumpFile("$git/games/test-game/config/game.yaml", "game:\n  id: test-game\n");
        $fs->dumpFile("$git/games/test-game/src/Scene/PlayScene.php", "<?php\n// version one\n");
        $run('git', 'init', '-q', '-b', 'main');
        $run('git', 'add', '-A');
        $run('git', 'commit', '-q', '-m', 'First version');
        $first = trim((new \Symfony\Component\Process\Process(['git', 'rev-parse', 'HEAD'], $git))->mustRun()->getOutput());
        $fs->dumpFile("$git/README.md", "not the game\n");
        $run('git', 'add', '-A');
        $run('git', 'commit', '-q', '-m', 'Readme');
        $fs->dumpFile("$git/games/test-game/src/Scene/PlayScene.php", "<?php\n// version two\n");
        $fs->dumpFile("$git/games/test-game/src/Entity/New.php", "<?php\n// added later\n");
        $run('git', 'add', '-A');
        $run('git', 'commit', '-q', '-m', 'Second version');
        $mirror = static::getContainer()->get(\App\Service\Publisher::class)->repoDir($this->project);
        $fs->mkdir(dirname($mirror));
        (new \Symfony\Component\Process\Process(['git', 'clone', '-q', '--mirror', $git, $mirror]))->mustRun();
        $fs->remove($git);
        // the published files are the second version
        $published = static::getContainer()->getParameter('app.storage_dir') . "/published/{$this->project->getId()}/" . self::SHA . '/game';
        $fs->dumpFile("$published/src/Scene/PlayScene.php", "<?php\n// version two\n");
        $fs->dumpFile("$published/src/Entity/New.php", "<?php\n// added later\n");

        $crawler = $this->client->request('GET', 'http://' . self::APP . '/test-game/versions');
        self::assertCount(2, $crawler->filter('.versions > li'));
        self::assertSelectorTextContains('.versions > li:last-child', 'First version');
        // played again: extracted, checked, its own manifest and files on the games origin
        $crawler = $this->client->request('GET', 'http://' . self::APP . "/test-game/versions/$first");
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("/test-game/play/?version=$first", (string)$crawler->filter('iframe.frame')->attr('src'));
        $this->get(self::PLAY, "/test-game/play/?version=$first");
        self::assertStringContainsString("manifest.json?version=$first", (string)$this->client->getResponse()->getContent());
        $this->get(self::PLAY, "/test-game/play/manifest.json?version=$first");
        $files = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        self::assertArrayNotHasKey('src/Entity/New.php', $files);
        $this->get(self::PLAY, '/test-game/play/files/src/Scene/PlayScene.php?v=' . $files['src/Scene/PlayScene.php']['hash']);
        self::assertStringContainsString('version one', $this->client->getInternalResponse()->getContent());
        // the newest is still what everyone plays
        $this->get(self::PLAY, '/test-game/play/manifest.json');
        $now = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], null, 'path');
        self::assertNotSame($files['src/Scene/PlayScene.php']['hash'], $now['src/Scene/PlayScene.php']['hash']);
        // a commit that is none of the game's
        $this->get(self::APP, '/test-game/versions/' . str_repeat('ab', 20));
        self::assertResponseRedirects('/test-game/versions');

        // edited in the sandbox: the files that differ from the published version
        $crawler = $this->client->request('GET', 'http://' . self::APP . "/test-game/versions/$first");
        $this->client->submit($crawler->selectButton('✏️ Bac à sable')->form());
        self::assertResponseRedirects('/bac-a-sable/test-game');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', '2 fichier(s) diffèrent');
        $api = 'http://' . self::APP . $crawler->filter('#editor')->attr('data-api');
        $this->client->request('GET', "$api/tree", [], [], ['HTTP_X_CSRF_TOKEN' => $crawler->filter('#editor')->attr('data-csrf')]);
        $states = array_column(json_decode((string)$this->client->getResponse()->getContent(), true)['files'], 'state', 'path');
        self::assertSame(['modified', 'deleted'], [$states['src/Scene/PlayScene.php'], $states['src/Entity/New.php']]);
        // the owner restores it from their editor
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', 'http://' . self::APP . "/test-game/versions/$first");
        $this->client->submit($crawler->selectButton('↩ Restaurer')->form());
        self::assertResponseRedirects('/test-game/edit');
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
