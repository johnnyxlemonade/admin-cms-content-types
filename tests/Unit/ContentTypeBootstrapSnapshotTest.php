<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Record\ContentTypeBootstrapSnapshot;
use PHPUnit\Framework\TestCase;

final class ContentTypeBootstrapSnapshotTest extends TestCase
{
    /**
     * Both accessors preserve a terminal failure without invoking the source again
     */
    public function testBothAccessorsRethrowOriginalFailure(): void
    {
        $snapshot = new ContentTypeBootstrapSnapshot();
        $failure = new \Error('Catalog query failed');
        $loads = 0;
        $snapshot->defer(static function () use ($failure, &$loads): array {
            $loads++;
            throw $failure;
        });
        foreach ([$snapshot->modules(...), $snapshot->fields(...), $snapshot->modules(...), $snapshot->fields(...)] as $read) {
            try {
                $read();
                self::fail('Failed snapshot returned a catalog');
            } catch (\Error $actual) {
                self::assertSame($failure, $actual);
            }
        }
        self::assertSame(1, $loads);
    }

    /**
     * A recursive read cannot observe an incomplete catalog
     */
    public function testRejectsReentrantReads(): void
    {
        $snapshot = new ContentTypeBootstrapSnapshot();
        $snapshot->defer(static function () use ($snapshot): array {
            return ['modules' => $snapshot->modules(), 'fields' => []];
        });
        $this->expectException(\LogicException::class);
        $snapshot->fields();
    }

    /**
     * An unconfigured snapshot cannot silently represent an empty catalog
     */
    public function testRejectsUnconfiguredReads(): void
    {
        $this->expectException(\LogicException::class);
        (new ContentTypeBootstrapSnapshot())->modules();
    }

    /**
     * Reuses the complete successfully loaded catalog
     */
    public function testItLoadsTheDeferredCatalogOnceOnFirstUse(): void
    {
        $loads = 0;
        $snapshot = new ContentTypeBootstrapSnapshot();
        $snapshot->defer(static function () use (&$loads): array {
            $loads++;

            return ['modules' => [], 'fields' => [1 => []]];
        });

        self::assertSame(0, $loads);
        self::assertSame([1 => []], $snapshot->fields());
        self::assertSame(1, $loads);
        self::assertSame([], $snapshot->modules());
        self::assertSame(1, $loads);
    }
}
