<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Public\PublishedRecordCollection;
use Lemonade\Framework\Component\Pagination\PaginationState;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that published record collections supply framework pagination state
 */
final class PublishedRecordCollectionTest extends TestCase
{
    /**
     * Preserves page totals and the host's filters in navigation URLs
     */
    public function testCreatesFrameworkPaginationForPublicRoute(): void
    {
        $collection = new PublishedRecordCollection([], total: 18, page: 2, perPage: 5);

        $pagination = $collection->getPagination(
            basePath: '/workplace-news',
            pageName: 'p',
            query: ['workplace' => 12, 'category' => 'internal', 'p' => 2],
        );

        self::assertInstanceOf(PaginationState::class, $pagination);
        self::assertSame(2, $pagination->currentPage());
        self::assertSame(5, $pagination->perPage());
        self::assertSame(18, $pagination->total());
        self::assertSame(5, $pagination->offset());
        self::assertSame(4, $pagination->lastPage());
        self::assertSame('/workplace-news?workplace=12&category=internal&p=3', $pagination->url(3));
    }
}
