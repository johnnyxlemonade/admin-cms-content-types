<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Exception;

use RuntimeException;

/**
 * Vraci lokalizovane chyby definice pod jmeny editorovych poli
 */
final class DefinitionValidationException extends RuntimeException
{
    /**
     * Nastavuje chyby pro standardni editorovy transport
     *
     * @param array<string,string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid content definition.');
    }

    /**
     * Vraci lokalizovane chyby definice
     *
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
