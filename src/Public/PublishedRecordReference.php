<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Public;

/**
 * Provides a lightweight published record reference for host navigation
 */
final readonly class PublishedRecordReference
{
    /**
     * Creates a localized record reference
     */
    public function __construct(
        private int $id,
        private string $title,
        private string $label,
        private ?string $publicUrl,
        private ?string $group = null,
    ) {}

    /**
     * Returns the record identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the published record title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the projected navigation label
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Returns the localized canonical URL when available
     */
    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the optional projected grouping value
     */
    public function getGroup(): ?string
    {
        return $this->group;
    }
}
