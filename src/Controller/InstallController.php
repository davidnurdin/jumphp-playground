<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JumPHP's installers in one line (docs: en-local): `curl -fsSL https://jumphp.net/install.sh | sh` (Linux, macOS,
 * WSL), `irm https://jumphp.net/install.ps1 | iex` (Windows: in WSL). They live in the JumPHP repository.
 */
#[Route(condition: "request.getHost() != env('PLAY_HOST')")]
final class InstallController extends AbstractController
{
    private const SOURCE = 'https://raw.githubusercontent.com/davidnurdin/jumPhp/main/';

    #[Route('/install.sh', name: 'install_sh', methods: ['GET'])]
    #[Route('/install.ps1', name: 'install_ps1', methods: ['GET'])]
    public function install(string $_route): RedirectResponse
    {
        return new RedirectResponse(self::SOURCE . ($_route === 'install_ps1' ? 'install.ps1' : 'install.sh'), 302, ['Cache-Control' => 'public, max-age=300']);
    }
}
