<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Http\Controller\ModuleController;
use Lemonade\Cms\ContentTypes\DataGrid\ContentTypesDataGrid;
use Lemonade\Cms\ContentTypes\Models\ContentTypeModel;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class ContentTypesDataGridTest extends TestCase
{
    public function testSearchAndPaginationUseSharedDataGridTransport(): void
    {
        $queries = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([['id' => 7, 'code' => 'document', 'name' => 'Documents', 'enabled' => 1, 'deleted_at' => null]]);
        $result->method('row_array')->willReturn(['numrows' => 26]);
        $driver->method('query')->willReturnCallback(static function (string $sql, array|false $bindings) use (&$queries, $result): DatabaseResultInterface {
            $queries[] = ['sql' => $sql, 'bindings' => $bindings];

            return $result;
        });
        $grid = $this->grid($driver);
        $page = $grid->execute(new DataGridQuery(2, 25, 'code', 'desc', 'doc', []));

        self::assertSame('cms.content-types.view', $grid->permission());
        self::assertSame(26, $page->total());
        self::assertSame(2, $page->page());
        self::assertSame(25, $page->perPage());
        self::assertCount(1, $page->items());
        self::assertCount(2, $queries);
        foreach ($queries as $query) {
            self::assertStringContainsString('(name LIKE ? OR code LIKE ?)', $query['sql']);
            self::assertStringContainsString('deleted_at IS NULL', $query['sql']);
            self::assertSame(['%doc%', '%doc%'], $query['bindings']);
        }
        self::assertStringContainsString('ORDER BY code DESC, id ASC', $queries[0]['sql']);
        self::assertStringContainsString('LIMIT 25', $queries[0]['sql']);
    }

    public function testDeletedViewFiltersBothQueries(): void
    {
        $queries = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([]);
        $result->method('row_array')->willReturn(['numrows' => 0]);
        $driver->method('query')->willReturnCallback(static function (string $sql) use (&$queries, $result): DatabaseResultInterface {
            $queries[] = $sql;
            return $result;
        });
        $grid = $this->grid($driver);
        self::assertSame('active', $grid->dataGridDefinition()->defaultView());
        self::assertFalse($grid->dataGridDefinition()->showAllView());
        $grid->execute(new DataGridQuery(1, 25, 'name', 'asc', '', ['status' => 'deleted']));
        self::assertCount(2, $queries);
        foreach ($queries as $sql) {
            self::assertStringContainsString('deleted_at IS NOT NULL', $sql);
        }
    }

    public function testEmptyCatalogReturnsAnEmptyPage(): void
    {
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([]);
        $result->method('row_array')->willReturn(['numrows' => 0]);
        $driver->method('query')->willReturn($result);
        $grid = $this->grid($driver);

        $page = $grid->execute(new DataGridQuery(1, 25, 'name', 'asc', '', []));

        self::assertSame([], $page->items());
        self::assertSame(0, $page->total());
    }

    public function testManagementActionsRespectPermissionAndEnabledState(): void
    {
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([
            ['id' => 7, 'code' => 'active', 'name' => 'Active', 'enabled' => 1, 'deleted_at' => null],
            ['id' => 8, 'code' => 'inactive', 'name' => 'Inactive', 'enabled' => 0, 'deleted_at' => null],
            ['id' => 9, 'code' => 'deleted', 'name' => 'Deleted', 'enabled' => 0, 'deleted_at' => '2026-11-06 12:00:00'],
        ]);
        $result->method('row_array')->willReturn(['numrows' => 2]);
        $driver->method('query')->willReturn($result);
        $query = new DataGridQuery(1, 25, 'name', 'asc', '', []);
        $grid = $this->grid($driver, true);
        self::assertContains('actions', array_map(static fn($column): string => $column->key(), $grid->dataGridDefinition()->columns()));
        $rows = $grid->execute($query)->items();
        $active = $rows[0]->toArray();
        $inactive = $rows[1]->toArray();
        $deleted = $rows[2]->toArray();
        self::assertSame(['restore'], array_column($deleted['actions'], 'key'));
        self::assertIsString($deleted['cells']['name']);
        self::assertSame(['edit', 'disable', 'delete'], array_column($active['actions'], 'key'));
        self::assertSame(['edit', 'enable', 'delete'], array_column($inactive['actions'], 'key'));
        self::assertSame('/admin/cms/content-types/edit/7', $active['actions'][0]['url']);
        self::assertSame('navigate', $active['actions'][0]['kind'] ?? null);
        self::assertSame('secondary', $active['actions'][0]['placement'] ?? null);
        self::assertSame('GET', $active['actions'][0]['method']);
        foreach ($this->grid($driver)->execute($query)->items() as $row) {
            self::assertSame([], $row->toArray()['actions']);
            self::assertIsString($row->toArray()['cells']['name']);
        }
    }

    private function grid(DatabaseDriverInterface $driver, bool $manage = false): ContentTypesDataGrid
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);

        $principals = $this->createMock(CurrentPrincipalProviderInterface::class);
        $principals->method('currentUser')->willReturn(new AuthenticatedUser(1, 'viewer@example.test'));
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(static function (string $sql) use ($manage): array {
            if (!str_contains($sql, 'SELECT DISTINCT p.code')) {
                return [];
            }
            $codes = ['cms.content-types.view'];
            if ($manage) {
                $codes[] = 'cms.content-types.manage_definitions';
            }
            return array_map(static fn(string $code): array => ['code' => $code, 'name_key' => $code, 'module_code' => 'cms.content-types'], $codes);
        });
        $actions = $this->createMock(ModuleActionPresentationFactory::class);
        $actions->method('rowAction')->willReturnCallback(static fn(string $moduleCode, string $action, int $entityId): DataGridRowActionDefinition => new DataGridRowActionDefinition($action, $action, '/actions/' . $entityId, 'POST'));
        $router = new Router();
        $router->getNamed('admin.cms.module.edit', '/admin/cms/{module}/edit/{id}', ControllerAction::for(ModuleController::class, 'edit'));

        return new ContentTypesDataGrid(
            new ContentTypeModel($driver),
            new AuthorizationService($principals, new Database($connection, $driver)),
            new UrlGenerator($router),
            $translator,
            $actions,
        );
    }
}
