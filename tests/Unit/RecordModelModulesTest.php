<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dynamic module ordering for Admin navigation
 */
final class RecordModelModulesTest extends TestCase
{
    /**
     * Orders modules by the system position and stable identifier fallback
     */
    public function testOrdersModulesForAdminNavigation(): void
    {
        $queries = [];
        $rows = [
            ['id' => 11, 'name' => 'Disabled', 'code' => 'disabled', 'sort_order' => 8, 'enabled' => 0, 'deleted_at' => null, 'description' => null],
            ['id' => 10, 'name' => 'News', 'code' => 'news', 'sort_order' => 5, 'enabled' => 1, 'deleted_at' => null, 'description' => null],
            ['id' => 2, 'name' => 'Workplaces', 'code' => 'workplaces', 'sort_order' => 5, 'enabled' => 1, 'deleted_at' => null, 'description' => null],
        ];
        $model = $this->model($rows, $queries);

        $modules = $model->modules();

        self::assertCount(2, $queries);
        self::assertStringContainsString('cms_content_type_route_prefix', $queries[0]);
        self::assertStringContainsString('ORDER BY id ASC', $queries[1]);
        self::assertSame([2, 10, 11], array_map(static fn($module): int => $module->id(), $modules));
        self::assertSame([60, 61, 62], array_map(static fn($module): int => $module->adminMetadata()->navigationOrder(), $modules));
        self::assertTrue($modules[0]->adminMetadata()->navigationVisible());
        self::assertFalse($modules[2]->adminMetadata()->navigationVisible());
    }

    /**
     * Keeps the previous identifier order while the migration is still pending
     */
    public function testFallsBackToIdentifierBeforeSortOrderMigration(): void
    {
        $queries = [];
        $model = $this->model([
            ['id' => 10, 'name' => 'News', 'code' => 'news', 'enabled' => 1, 'deleted_at' => null, 'description' => null],
            ['id' => 2, 'name' => 'Workplaces', 'code' => 'workplaces', 'enabled' => 1, 'deleted_at' => null, 'description' => null],
        ], $queries);

        $modules = $model->modules();

        self::assertSame([2, 10], array_map(static fn($module): int => $module->id(), $modules));
        self::assertSame([60, 61], array_map(static fn($module): int => $module->adminMetadata()->navigationOrder(), $modules));
    }

    /**
     * Creates a record model over prepared type rows
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $queries
     */
    private function model(array $rows, array &$queries): RecordModel
    {
        $prefixResult = self::createStub(DatabaseResultInterface::class);
        $prefixResult->method('result_array')->willReturn([]);
        $typeResult = self::createStub(DatabaseResultInterface::class);
        $typeResult->method('result_array')->willReturn($rows);
        $driver = self::createMock(DatabaseDriverInterface::class);
        $driver->expects(self::never())->method('tableExists');
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $driver->method('query')->willReturnCallback(static function (string $sql) use (&$queries, $prefixResult, $typeResult): DatabaseResultInterface {
            $queries[] = $sql;

            return str_contains($sql, 'cms_content_type_route_prefix') ? $prefixResult : $typeResult;
        });
        $database = new Database(self::createStub(ConnectionInterface::class), $driver);

        return new RecordModel($database, new FieldTypeRegistry([]));
    }
}
