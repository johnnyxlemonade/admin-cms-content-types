<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\AdminServiceProvider;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Routing\AdminModuleRouteRegistrar;
use Lemonade\Cms\ContentTypes\CmsContentTypesServiceProvider;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\Record\ContentTypeBootstrapSnapshot;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Container\ScopeKind;
use Lemonade\Framework\Database\Config\DatabaseRuntimeConfig;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * Exercises actual provider wiring across requests without a database server
 */
final class ContentTypeBootstrapScopeTest extends TestCase
{
    /**
     * Login stays lazy, models reuse the catalog, and the next scope sees new definitions
     */
    public function testEachScopeLoadsAndRegistersItsOwnCatalogOnce(): void
    {
        $typeId = 1;
        $queries = [];
        $driver = self::createMock(DatabaseDriverInterface::class);
        $driver->expects(self::never())->method('tableExists');
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $driver->method('query')->willReturnCallback(function (string $sql) use (&$typeId, &$queries): DatabaseResultInterface {
            $queries[] = $sql;
            $rows = [];
            if (str_contains($sql, 'FROM cms_content_type ')) {
                $rows = [['id' => $typeId, 'name' => 'Type ' . $typeId, 'code' => 'type_' . $typeId, 'enabled' => 1, 'deleted_at' => null, 'description' => null]];
            } elseif (str_contains($sql, 'FROM cms_content_field ')) {
                $rows = [['id' => $typeId, 'type_id' => $typeId, 'name' => 'Field ' . $typeId, 'code' => 'text', 'kind' => 'text', 'archived_at' => null]];
            }
            $result = self::createStub(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);
            return $result;
        });
        $root = new Container();
        (new AdminServiceProvider())->register($root);
        $provider = new CmsContentTypesServiceProvider();
        $provider->register($root);
        $root->singleton(DatabaseDriverInterface::class, $driver);
        $root->singleton(Database::class, new Database(self::createStub(ConnectionInterface::class), $driver));
        $root->singleton(DatabaseRuntimeConfig::class, new DatabaseRuntimeConfig('default', ['default' => DatabaseConfig::fromArray(['driver' => 'mysql'])]));
        $root->freeze();
        $provider->boot($root);
        $router = new Router();
        $router->group('/admin', static function (Router $router) use ($root): void {
            (new AdminModuleRouteRegistrar($root->get(AdminModuleRegistry::class)))->registerRoutes($router);
        });
        $router->get('/admin/login', ControllerAction::for('LoginController', 'index'));
        $router->freeze();

        $first = $root->beginScope(ScopeKind::Request);
        $modules = $first->get(AdminModuleRegistry::class);
        $request = (new ServerRequest('GET', '/admin/login'))->withAttribute(AdminModuleRegistry::class, $modules);
        $router->match($request);
        self::assertSame([], $queries);
        self::assertSame([], $modules->registeredWithoutLoading());
        $router->match($request->withUri($request->getUri()->withPath('/admin/content/type-type-1')));
        $firstSnapshot = $first->get(ContentTypeBootstrapSnapshot::class);
        self::assertSame($firstSnapshot->modules(), $first->get(RecordModel::class)->modules());
        self::assertSame($firstSnapshot->fields()[1], $first->get(DefinitionModel::class)->fields(1));
        self::assertCount(5, $queries);
        self::assertNotNull($first->get(PermissionCatalogRegistry::class)->definition('cms.type_1.view'));
        self::assertTrue($first->get(EditorRegistry::class)->has('cms.type_1'));
        $first->close();

        $typeId = 2;
        $second = $root->beginScope(ScopeKind::Request);
        $secondModules = $second->get(AdminModuleRegistry::class);
        self::assertNotSame($modules, $secondModules);
        self::assertNotSame($firstSnapshot, $second->get(ContentTypeBootstrapSnapshot::class));
        // A public model may read the snapshot before any Admin contribution is needed.
        self::assertSame(2, $second->get(RecordModel::class)->modules()[0]->id());
        self::assertSame('Field 2', $second->get(DefinitionModel::class)->fields(2)[0]['name']);
        self::assertSame([], $secondModules->registeredWithoutLoading());
        self::assertCount(10, $queries);
        self::assertTrue($secondModules->has('cms.type_2'));
        self::assertFalse($secondModules->has('cms.type_1'));
        self::assertNull($second->get(PermissionCatalogRegistry::class)->definition('cms.type_1.view'));
        self::assertTrue($second->get(EditorRegistry::class)->has('cms.type_2'));
        self::assertFalse($second->get(EditorRegistry::class)->has('cms.type_1'));
        self::assertCount(10, $queries);
        self::assertSame([], $root->get(AdminModuleRegistry::class)->registeredWithoutLoading());
        $second->close();
    }
}
