<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Cms\Content\CmsContentValidation;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\Content\CmsRoot;

final readonly class RecordData
{
    /**
     * @param array<string,mixed> $fields
     */
    public function __construct(#[CmsRoot('author_name')] private ?string $authorName, private array $fields) {}

    /**
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
     * @return array<string,mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function authorName(): ?string
    {
        return $this->authorName;
    }
}
