<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Public;

use DateTimeImmutable;

/**
 * Provides a visible Content Type record candidate for a host sitemap
 */
final readonly class PublishedRecordSitemapEntry
{
    /**
     * Creates a sitemap candidate with its type and last public update
     */
    public function __construct(
        private string $publicUrl,
        private DateTimeImmutable $updatedAt,
        private string $typeCode,
    ) {}

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the latest change to the record, translation or canonical route
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the Content Type code used by host sitemap filters
     */
    public function getTypeCode(): string
    {
        return $this->typeCode;
    }
}
