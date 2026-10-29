<?php

// The tester: runs the games' PHPUnit tests that the playground asks for (App\Service\TestRunner), one at a time.
// It sees only the tests volume (/tests) and has no network. It runs as root without capabilities but those to
// become another user: PHPUnit runs as nobody (65534) in a copy of the game in /sandbox (tmpfs), with a time and
// a memory limit, and nothing it started survives it. nobody cannot write to /tests: a game cannot change what
// is reported of another.

const ROOT = '/tests';
const NOBODY = '65534:65534';
const TIMEOUT = 120;            // seconds
const MAX_OUTPUT = 200_000;     // bytes of PHPUnit's output kept

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
    $command = ['su-exec', NOBODY, 'timeout', '-s', 'KILL', (string)TIMEOUT,
        'php', '-d', 'memory_limit=256M', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
        '/opt/phpunit.phar', '--no-configuration', '--bootstrap', 'vendor/autoload.php', '--log-junit', $junit,
        '--testdox', '--colors=never', '--do-not-cache-result', 'tests'];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $dir,
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $dir, 'TMPDIR' => $dir, 'SDL_VIDEODRIVER' => 'dummy', 'SDL_AUDIODRIVER' => 'dummy']);
    // read until PHPUnit ends (not until the pipe closes: something it left behind may hold it), or the deadline
    $output = '';
    $code = -1;
    $deadline = time() + TIMEOUT + 10;
    stream_set_blocking($pipes[1], false);
    while (true) {
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
    sh(['su-exec', NOBODY, 'kill', '-9', '-1']);
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

function sh(array $command): void
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }
}
