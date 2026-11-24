<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Public;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\PublishedMediaInterface;
use Lemonade\Cms\ContentTypes\Record\RecordFieldValue;

/**
 * Represents a published translation with its visible fields and media
 */
final readonly class PublishedRecord implements PublishedMediaInterface
{
    /**
     * Creates a complete published record projection
     *
     * @param array<string,RecordFieldValue> $fields
     * @param list<FileMetadata> $gallery
     * @param list<FileMetadata> $attachments
     * @param array<string,list<FileMetadata>> $fieldFiles
     * @param list<string> $tags
     */
    public function __construct(
        private int $id,
        private string $typeCode,
        private string $locale,
        private string $title,
        private DateTimeImmutable $publishedAt,
        private DateTimeImmutable $updatedAt,
        private ?string $publicUrl,
        private array $fields,
        private ?FileMetadata $thumbnail,
        private array $gallery,
        private array $attachments,
        private array $fieldFiles,
        private array $tags,
        private ?string $pageTitle,
        private ?string $metaDescription,
        private ?string $authorName,
        private bool $recommended,
        private bool $showAuthor,
        private bool $sharingEnabled,
        private bool $showPublishedAt,
        private bool $showThumbnail,
        private bool $showReadingTime,
        private bool $showTags,
    ) {}

    /**
     * Returns the record identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the Content Type code
     */
    public function getTypeCode(): string
    {
        return $this->typeCode;
    }

    /**
     * Returns the published translation locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the published title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Returns the publication time
     */
    public function getPublishedAt(): DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * Returns the last translation update time
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the canonical URL when one is reserved
     */
    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    /**
     * Returns active field values by code
     *
     * @return array<string,RecordFieldValue>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns one active field value or rejects an unknown code
     */
    public function getField(string $code): RecordFieldValue
    {
        return $this->fields[$code] ?? throw new InvalidArgumentException('Unknown readable field: ' . $code);
    }

    /**
     * Returns the visible system thumbnail image when one is available
     */
    public function getThumbnail(): ?FileMetadata
    {
        return $this->thumbnail;
    }

    /**
     * Returns system gallery images in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getGallery(): array
    {
        return $this->gallery;
    }

    /**
     * Returns system attachments in their stored order
     *
     * @return list<FileMetadata>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * Returns media attached to a known custom media field
     *
     * @return list<FileMetadata>
     */
    public function getFieldFiles(string $fieldCode): array
    {
        if ($this->getField($fieldCode)->getMediaUsage() === null) {
            throw new InvalidArgumentException('Field is not media: ' . $fieldCode);
        }
        return $this->fieldFiles[$fieldCode] ?? [];
    }

    /**
     * Returns visible localized tags
     *
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Returns the page title when configured
     */
    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }

    /**
     * Returns the meta description when configured
     */
    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    /**
     * Returns the author name when display is enabled
     */
    public function getAuthorName(): ?string
    {
        return $this->showAuthor ? $this->authorName : null;
    }

    /**
     * Reports whether the record is recommended
     */
    public function isRecommended(): bool
    {
        return $this->recommended;
    }

    /**
     * Reports whether the author is shown
     */
    public function isAuthorShown(): bool
    {
        return $this->showAuthor;
    }

    /**
     * Reports whether sharing is enabled
     */
    public function isSharingEnabled(): bool
    {
        return $this->sharingEnabled;
    }

    /**
     * Reports whether the publication date is shown
     */
    public function isPublishedAtShown(): bool
    {
        return $this->showPublishedAt;
    }

    /**
     * Reports whether the system thumbnail is shown
     */
    public function isThumbnailShown(): bool
    {
        return $this->showThumbnail;
    }

    /**
     * Reports whether reading time is shown
     */
    public function isReadingTimeShown(): bool
    {
        return $this->showReadingTime;
    }

    /**
     * Reports whether tags are shown
     */
    public function isTagsShown(): bool
    {
        return $this->showTags;
    }
}
