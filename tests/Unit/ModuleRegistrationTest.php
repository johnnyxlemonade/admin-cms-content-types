<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\Authorization\PermissionDependencyResolver;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Modules\Catalog\ModuleCatalog;
use Lemonade\Admin\Modules\ModuleBootstrapServiceProvider;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
use Lemonade\Cms\ContentTypes\ContentTypesModulePageProvider;
use Lemonade\Cms\ContentTypes\DataGrid\ContentTypesDataGrid;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeSystemMedia;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeThumbnail;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentFieldDefinitions;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentRecords;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentTypes;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Core\Context\ApplicationContextFactory;
use Lemonade\Framework\Database\Migration\MigrationRegistry;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\View\ViewResourceRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistrationTest extends TestCase
{
    public function testComposerDiscoveryRegistersNavigationGridPermissionAndMigration(): void
    {
        $temporary = sys_get_temp_dir() . '/content-types-' . bin2hex(random_bytes(8));
        mkdir($temporary . '/vendor/composer', 0775, true);
        $metadata = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $metadata['version'] = 'dev-main';
        file_put_contents($temporary . '/vendor/composer/installed.json', json_encode(['packages' => [$metadata]], JSON_THROW_ON_ERROR));

        try {
            $catalog = new ModuleCatalog((new ApplicationContextFactory())->create($temporary, ['APP_ENV' => 'testing']));
            self::assertTrue($catalog->has(ModuleManifest::CODE));
            $container = new Container();
            $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
            $permissions = new PermissionCatalogRegistry();
            $grids = new DataGridRegistry();
            $pages = new ModulePageRegistry();
            $editors = new EditorRegistry();
            $migrations = new MigrationRegistry($container);
            foreach ([
                AdminModuleRegistry::class => $modules,
                PermissionCatalogRegistry::class => $permissions,
                DataGridRegistry::class => $grids,
                ModulePageRegistry::class => $pages,
                EditorRegistry::class => $editors,
                ModuleActionRegistry::class => new ModuleActionRegistry(),
                ViewResourceRegistry::class => new ViewResourceRegistry(),
                MigrationRegistry::class => $migrations,
                AuditEventPresentationRegistry::class => new AuditEventPresentationRegistry(),
                AdminFileUsageRegistry::class => new AdminFileUsageRegistry(),
                TranslationResourceRegistry::class => new TranslationResourceRegistry(),
                ClientTranslationGroupRegistry::class => new ClientTranslationGroupRegistry(),
            ] as $service => $instance) {
                $container->instance($service, $instance);
            }
            $bootstrap = new ModuleBootstrapServiceProvider($catalog);
            $bootstrap->register($container);
            $container->freeze();
            $bootstrap->boot($container);

            $routes = new AdminModuleRouteResolver($modules);
            $metadata = $routes->metadata('content-types');
            self::assertSame(AdminNavigationGroup::Cms, $metadata->navigationGroup());
            self::assertSame('cms.content-types.view', $metadata->navigationPermission());
            self::assertSame('admin.cms.module.index', $routes->managementRouteName('content-types', 'index'));
            self::assertSame(ModuleManifest::CODE, $modules->moduleCodeByRouteSegment('content-types'));
            self::assertNotNull($permissions->definition('cms.content-types.view'));
            self::assertCount(2, $permissions->all());
            self::assertSame(['cms.content-types.view', 'cms.content-types.manage_definitions'], (new PermissionDependencyResolver($permissions))->withPrerequisites(['cms.content-types.manage_definitions']));
            foreach (['enable', 'disable', 'delete', 'restore'] as $action) {
                $definition = $container->get(ModuleActionRegistry::class)->action(ModuleManifest::CODE, $action)['definition'];
                self::assertSame('cms.content-types.manage_definitions', $definition->permission());
                self::assertTrue($definition->refreshGrid());
                self::assertNotNull($definition->confirmation());
            }
            foreach (['archive', 'purge'] as $action) {
                self::assertFalse($container->get(ModuleActionRegistry::class)->has(ModuleManifest::CODE, $action));
            }
            self::assertSame(ContentTypesDataGrid::class, $grids->providerServiceId(ModuleManifest::CODE));
            self::assertSame(ContentTypesModulePageProvider::class, $pages->indexProviderServiceId(ModuleManifest::CODE));
            self::assertTrue($editors->has(ModuleManifest::CODE));
            self::assertSame([CreateContentTypes::identifier(), CreateContentFieldDefinitions::identifier(), CreateContentRecords::identifier(), AddContentTypeSystemMedia::identifier(), AddContentTypeThumbnail::identifier()], $migrations->identifiers());
            self::assertTrue($container->get(ClientTranslationGroupRegistry::class)->has('content-types'));
            $audit = $container->get(AuditEventPresentationRegistry::class);
            self::assertSame('content-types.module.name', $audit->modulePresentation(ModuleManifest::CODE)?->nameKey());
            foreach (['created', 'updated', 'enabled', 'disabled', 'deleted', 'restored'] as $event) {
                $presentation = $audit->presentation(ModuleManifest::CODE . '.' . $event);
                self::assertNotNull($presentation);
                self::assertSame('content-types.audit.' . $event, $presentation->resolvedTranslationKey([]));
            }
        } finally {
            unlink($temporary . '/vendor/composer/installed.json');
            rmdir($temporary . '/vendor/composer');
            rmdir($temporary . '/vendor');
            rmdir($temporary);
        }
    }
}
