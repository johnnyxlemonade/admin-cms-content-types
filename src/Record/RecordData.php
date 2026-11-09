<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Cms\Content\CmsContentValidation;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\Content\CmsRoot;

/**
 * Oddeluje systemoveho autora od dynamickeho vstupu hodnot
 */
final readonly class RecordData
{
    /**
     * Predava hodnoty writeru bez jejich mapovani na root sloupce
     *
     * @param array<string,mixed> $fields
     */
    public function __construct(#[CmsRoot('author_name')] private ?string $authorName, private array $fields) {}

    /**
     * Overi systemova metadata a zachova whitelistovany vstup editoru
     *
     * @param array<string,mixed> $data
     */
    public static function fromEditor(array $data): self
    {
        $author = CmsContentValidation::texts($data, ['author_name'])['author_name'] ?? '';
        if (mb_strlen($author) > 255) {
            throw new CmsContentValidationException(['author_name' => 'records.validation.author_max_length']);
        }
        return new self($author === '' ? null : $author, $data);
    }

    /**
     * Vrati vstup dynamickych hodnot pro validaci definici
     *
     * @return array<string,mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Vraci jmeno autora obsahoveho zaznamu
     */
    public function authorName(): ?string
    {
        return $this->authorName;
    }
}
