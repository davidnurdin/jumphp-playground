<?php

// The tester: runs the games' PHPUnit tests that the playground asks for (App\Service\TestRunner), one at a time.
// It sees only the tests volume (/tests) and has no network. It runs as root without capabilities but those to
// become another user: PHPUnit runs as nobody (65534) in a copy of the game in /sandbox (tmpfs), with a time and
// a memory limit, and nothing it started survives it. nobody cannot write to /tests: a game cannot change what
// is reported of another. The game tests (JumPHP\Testing\GameTestCase) are filmed (JUMPHP_FILM): their frames go,
// as the tests run, to films/<id>/ (index.json + <n>/<frame>.png), which the playground replays.

const ROOT = '/tests';
const NOBODY = '65534:65534';
const TIMEOUT = 120;            // seconds
const MAX_OUTPUT = 200_000;     // bytes of PHPUnit's output kept
const FILM_TESTS = 60;          // tests filmed at most
const FILM_FRAMES = 150;        // frames a test at most
const FILM_FRAME = 512 * 1024;  // bytes a frame at most

// the playground makes the folders (they are its own, 0755: nobody cannot write there); root writes the results
// a run cut by a restart: done again
foreach (glob(ROOT . '/jobs/*.running') ?: [] as $file) {
    rename($file, substr($file, 0, -strlen('.running')) . '.json');
}
fwrite(STDERR, "[tester] ready\n");

while (true) {
    foreach (glob(ROOT . '/jobs/*.json') ?: [] as $job) {
        $id = basename($job, '.json');
        if (!ctype_digit($id)) {
            @unlink($job);
            continue;
        }
        $running = ROOT . "/jobs/$id.running";
        if (!@rename($job, $running)) {
            continue;
        }
        $started = microtime(true);
        $result = run($id);
        @mkdir(ROOT . '/results', 0755);
        $file = ROOT . "/results/$id.json";
        file_put_contents("$file.tmp", json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename("$file.tmp", $file);
        @unlink($running);
        fwrite(STDERR, sprintf("[tester] run %s: %s (%.1fs)\n", $id, $result['status'], microtime(true) - $started));
    }
    usleep(500_000);
}

/** @return array{status: string, output: string, summary: array} */
function run(string $id): array
{
    $source = ROOT . "/work/$id";
    if (!is_dir($source)) {
        return ['status' => 'error', 'output' => 'The game\'s files are missing.', 'summary' => []];
    }
    $dir = "/sandbox/run-$id";
    sh(['rm', '-rf', $dir]);
    sh(['cp', '-R', $source, $dir]);
    sh(['chown', '-R', NOBODY, $dir]);

    $junit = "$dir/.junit.xml";
    $command = [...as_nobody(), 'timeout', '-s', 'KILL', (string)TIMEOUT,
        'php', '-d', 'memory_limit=256M', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
        '/opt/phpunit.phar', '--no-configuration', '--bootstrap', 'vendor/autoload.php', '--log-junit', $junit,
        '--testdox', '--colors=never', '--do-not-cache-result', 'tests'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $dir,
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $dir, 'TMPDIR' => $dir, 'SDL_VIDEODRIVER' => 'dummy', 'SDL_AUDIODRIVER' => 'dummy',
            'JUMPHP_FILM' => "$dir/.film"]);
    // read until PHPUnit ends (not until the pipe closes: something it left behind may hold it), or the deadline
    $output = '';
    $code = -1;
    $deadline = time() + TIMEOUT + 10;
    $filmed = 0.0;
    stream_set_blocking($pipes[1], false);
    while (true) {
        if (microtime(true) - $filmed > 1) {
            film("$dir/.film", $id);
            $filmed = microtime(true);
        }
        $read = [$pipes[1]];
        $none = null;
        if (@stream_select($read, $none, $none, 1) > 0) {
            $chunk = (string)fread($pipes[1], 65536);
            if (strlen($output) < MAX_OUTPUT) {
                $output .= $chunk;
            }
        }
        $state = proc_get_status($process);
        if (!$state['running']) {
            $code = $state['exitcode'];
            while (strlen($output) < MAX_OUTPUT && ($chunk = (string)fread($pipes[1], 65536)) !== '') {
                $output .= $chunk;
            }
            break;
        }
        if (time() > $deadline) {
            $code = 137;
            break;
        }
    }
    // nothing the tests started stays: every process of nobody (kill -1 as nobody; root cannot see them in /proc)
    sh([...as_nobody(), 'kill', '-9', '-1']);
    film("$dir/.film", $id);
    fclose($pipes[1]);
    proc_close($process);

    $output = str_replace($dir . '/', '', $output);
    if (strlen($output) >= MAX_OUTPUT) {
        $output = substr($output, 0, MAX_OUTPUT) . "\n[…] (output cut)";
    }
    $summary = is_file($junit) ? junit($junit, $dir) : [];
    sh(['rm', '-rf', $dir]);
    sh(['rm', '-rf', $source]);

    if ($code === 137 || $code === 124) {
        return ['status' => 'error', 'output' => $output . "\n[!] Stopped after " . TIMEOUT . ' s.', 'summary' => $summary];
    }
    $status = match (true) {
        $code === 0 && ($summary['tests'] ?? 0) > 0 => 'passed',
        ($summary['failures'] ?? 0) + ($summary['errors'] ?? 0) > 0 => 'failed',
        default => 'error',
    };
    return ['status' => $status, 'output' => $output, 'summary' => $summary];
}

/** PHPUnit's JUnit report: the totals and each test */
function junit(string $file, string $dir): array
{
    $xml = @simplexml_load_string((string)file_get_contents($file, false, null, 0, 5_000_000), options: LIBXML_NONET);
    $suite = $xml?->testsuite;
    if (!$suite) {
        return [];
    }
    $cases = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $problem = $case->failure ?? $case->error ?? null;
        $status = match (true) {
            isset($case->failure) => 'failed',
            isset($case->error) => 'error',
            isset($case->skipped) => 'skipped',
            default => 'passed',
        };
        if (count($cases) < 500) {
            $cases[] = [
                'class' => (string)($case['class'] ?? ''),
                'name' => (string)$case['name'],
                'status' => $status,
                'message' => $problem !== null ? mb_substr(str_replace($dir . '/', '', (string)$problem), 0, 4000) : null,
                'time' => round((float)$case['time'], 3),
            ];
        }
    }
    return [
        'tests' => (int)$suite['tests'],
        'assertions' => (int)$suite['assertions'],
        'failures' => (int)$suite['failures'],
        'errors' => (int)$suite['errors'],
        'skipped' => (int)$suite['skipped'],
        'time' => round((float)$suite['time'], 3),
        'cases' => $cases,
    ];
}

/** the command run as nobody, without groups or new privileges */
function as_nobody(): array
{
    return ['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '--no-new-privs'];
}

/**
 * The films of a run's game tests (written by nobody in $from), copied to films/<id>/ for the playground: only
 * numbered folders and PNG frames, and the test's name, in index.json. The folder is the playground's (it serves
 * and removes it).
 */
function film(string $from, string $id): void
{
    if (!is_dir($from) || is_link($from)) {
        return;
    }
    $to = ROOT . "/films/$id";
    $owner = @fileowner(ROOT . '/films');
    if ($owner === false) {
        return;
    }
    $index = [];
    foreach (array_slice(glob("$from/[0-9][0-9][0-9]", GLOB_ONLYDIR) ?: [], 0, FILM_TESTS) as $test) {
        if (is_link($test)) {
            continue;
        }
        $n = basename($test);
        $name = is_file("$test/name.txt") && !is_link("$test/name.txt") ? (string)file_get_contents("$test/name.txt", false, null, 0, 300) : '';
        $frames = 0;
        foreach (array_slice(glob("$test/[0-9][0-9][0-9][0-9].png") ?: [], 0, FILM_FRAMES) as $frame) {
            $target = "$to/$n/" . basename($frame);
            if (is_file($target)) {
                $frames++;
                continue;
            }
            if (is_link($frame) || !is_file($frame) || filesize($frame) > FILM_FRAME) {
                continue;
            }
            $png = (string)file_get_contents($frame, false, null, 0, FILM_FRAME);
            // a whole PNG (it ends with IEND): one being written is taken at the next pass
            if (!str_starts_with($png, "\x89PNG\r\n\x1a\n") || !str_contains(substr($png, -12), 'IEND')) {
                continue;
            }
            mine("$to/$n", $owner);
            file_put_contents("$target.tmp", $png);
            @chown("$target.tmp", $owner);
            rename("$target.tmp", $target);
            $frames++;
        }
        $index[] = ['n' => $n, 'name' => mb_scrub($name), 'frames' => $frames];
    }
    if ($index) {
        mine($to, $owner);
        file_put_contents("$to/index.json.tmp", json_encode(['tests' => $index], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        @chown("$to/index.json.tmp", $owner);
        rename("$to/index.json.tmp", "$to/index.json");
    }
}

/** a folder of the playground's (its owner), made if needed */
function mine(string $dir, int $owner): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        @chown($dir, $owner);
        @chown(dirname($dir), $owner);
    }
}

function sh(array $command): void
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }
}
