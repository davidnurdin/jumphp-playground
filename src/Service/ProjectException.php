<?php

namespace App\Service;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A creator's action refused: the message is for them, in French (the portal's language, and the key of its
 * translation), its values apart as %placeholders% so that it can be shown in the visitor's language.
 */
final class ProjectException extends \RuntimeException implements TranslatableInterface
{
    /** @param array<string, string|int> $parameters */
    public function __construct(private string $key, private array $parameters = [])
    {
        parent::__construct(strtr($key, $parameters));
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, $this->parameters, null, $locale);
    }
}
