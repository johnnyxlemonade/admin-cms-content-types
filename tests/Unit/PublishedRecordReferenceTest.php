<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Public\PublishedRecordReference;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the lightweight public record reference contract
 */
final class PublishedRecordReferenceTest extends TestCase
{
    /**
     * Exposes projected navigation values without a full published record
     */
    public function testProvidesProjectedRecordValues(): void
    {
        $reference = new PublishedRecordReference(
            id: 42,
            title: 'Full title',
            label: 'Short label',
            publicUrl: '/records/full-title',
            group: 'regional',
        );

        self::assertSame(42, $reference->getId());
        self::assertSame('Full title', $reference->getTitle());
        self::assertSame('Short label', $reference->getLabel());
        self::assertSame('/records/full-title', $reference->getPublicUrl());
        self::assertSame('regional', $reference->getGroup());
    }

    /**
     * Preserves missing optional route and group values
     */
    public function testPreservesMissingOptionalValues(): void
    {
        $reference = new PublishedRecordReference(
            id: 7,
            title: 'Unrouted record',
            label: 'Unrouted record',
            publicUrl: null,
        );

        self::assertNull($reference->getPublicUrl());
        self::assertNull($reference->getGroup());
    }
}
