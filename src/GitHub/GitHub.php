<?php

namespace App\GitHub;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What the playground asks GitHub, with the token of the user it acts for (OAuth App, scopes SCOPES):
 * sign in, make a game's repository, its webhook, forks, commits of the online editor.
 */
class GitHub
{
    /** public repositories only: the games are public anyway */
    public const SCOPES = 'read:user public_repo write:repo_hook';

    public function __construct(
        private HttpClientInterface $githubHttp,
        private string $githubClientId,
        private string $githubClientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->githubClientId !== '' && $this->githubClientSecret !== '';
    }

    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => $this->githubClientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'allow_signup' => 'true',
        ]);
    }

    /** @return array{token: string, scopes: string} */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $data = $this->githubHttp->request('POST', 'https://github.com/login/oauth/access_token', [
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'jumphp-playground'],
            'body' => [
                'client_id' => $this->githubClientId,
                'client_secret' => $this->githubClientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
        ])->toArray(false);
        if (empty($data['access_token'])) {
            throw new GitHubException('GitHub refused the sign-in: ' . ($data['error_description'] ?? $data['error'] ?? 'no token'));
        }
        return ['token' => $data['access_token'], 'scopes' => (string)($data['scope'] ?? '')];
    }

    /** @return array{id: int, login: string, name: ?string, avatar_url: ?string} */
    public function user(string $token): array
    {
        return $this->call('GET', '/user', $token);
    }

    public function publicUser(string $login): array
    {
        return $this->call('GET', '/users/' . rawurlencode($login));
    }

    public function repo(string $fullName, ?string $token = null): ?array
    {
        $response = $this->send('GET', '/repos/' . $fullName, $token);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        return $this->decode($response, 'GET /repos/' . $fullName);
    }

    public function canPush(string $fullName, string $token): bool
    {
        return (bool)($this->repo($fullName, $token)['permissions']['push'] ?? false);
    }

    public function createRepo(string $token, string $name, string $description, string $homepage): array
    {
        return $this->call('POST', '/user/repos', $token, [
            'name' => $name,
            'description' => mb_substr($description, 0, 350),
            'homepage' => $homepage,
            'private' => false,
            'has_wiki' => false,
            'auto_init' => false,
        ]);
    }

    public function updateRepo(string $token, string $fullName, array $fields): array
    {
        return $this->call('PATCH', '/repos/' . $fullName, $token, $fields);
    }

    public function createHook(string $token, string $fullName, string $url, string $secret): int
    {
        $hook = $this->call('POST', "/repos/$fullName/hooks", $token, [
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);
        return (int)$hook['id'];
    }

    public function fork(string $token, string $fullName, string $name): array
    {
        return $this->call('POST', "/repos/$fullName/forks", $token, ['name' => $name, 'default_branch_only' => true]);
    }

    /**
     * One commit on $branch with these files (path => content, null = delete): blobs, a tree on top of the
     * branch's tree, the commit, the branch moved to it. Returns the new commit's sha.
     *
     * @param array<string, ?string> $files
     */
    public function commit(string $token, string $fullName, string $branch, array $files, string $message): string
    {
        $ref = $this->call('GET', "/repos/$fullName/git/ref/heads/" . rawurlencode($branch), $token);
        $parent = $ref['object']['sha'];
        $baseTree = $this->call('GET', "/repos/$fullName/git/commits/$parent", $token)['tree']['sha'];
        $tree = [];
        foreach ($files as $path => $content) {
            if ($content === null) {
                $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null];
                continue;
            }
            $blob = $this->call('POST', "/repos/$fullName/git/blobs", $token, ['content' => base64_encode($content), 'encoding' => 'base64']);
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
        }
        $newTree = $this->call('POST', "/repos/$fullName/git/trees", $token, ['base_tree' => $baseTree, 'tree' => $tree]);
        $commit = $this->call('POST', "/repos/$fullName/git/commits", $token, [
            'message' => $message,
            'tree' => $newTree['sha'],
            'parents' => [$parent],
        ]);
        $this->call('PATCH', "/repos/$fullName/git/refs/heads/" . rawurlencode($branch), $token, ['sha' => $commit['sha']]);
        return $commit['sha'];
    }

    private function send(string $method, string $path, ?string $token, ?array $json = null): ResponseInterface
    {
        $options = [];
        if ($token !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $token;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        return $this->githubHttp->request($method, $path, $options);
    }

    private function call(string $method, string $path, ?string $token = null, ?array $json = null): array
    {
        return $this->decode($this->send($method, $path, $token, $json), "$method $path");
    }

    private function decode(ResponseInterface $response, string $what): array
    {
        $status = $response->getStatusCode();
        $data = $response->toArray(false);
        if ($status >= 400) {
            $errors = implode('; ', array_map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : (string)$e, $data['errors'] ?? []));
            throw new GitHubException(sprintf('GitHub %s: %d %s%s', $what, $status, $data['message'] ?? '', $errors ? " ($errors)" : ''), $status);
        }
        return $data;
    }
}
