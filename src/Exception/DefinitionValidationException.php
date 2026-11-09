<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Exception;

use RuntimeException;

final class DefinitionValidationException extends RuntimeException
{
    /**
     * @param array<string,string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid content definition.');
    }

    /**
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
