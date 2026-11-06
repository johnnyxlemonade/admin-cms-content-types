<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Authorization\EffectivePermissionGroupViewModelFactory;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Routing\AdminModuleRouteRegistrar;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Oddeluje navigacni viditelnost od stabilnich prav a uzivatelskych popisku
 */
final class RecordPresentationTest extends TestCase
{
    /**
     * Neaktivni a prejmenovany typ zachovava prava i samostatnou sekci
     */
    public function testTypeChangesPreservePermissionsAndSeparateDefinitionManagement(): void
    {
        $originalCodes = null;
        foreach ([true, false, true] as $enabled) {
            $record = new RecordModule(9, $enabled ? 'Nabídky práce' : 'Volná místa', 'job_offer', $enabled, 'Pracovní nabídky a volná místa');
            $builder = new ModuleManifest();
            $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
            $modules->register($builder);
            $modules->register($record);
            $catalog = new PermissionCatalogRegistry();
            $catalog->register(...$builder->permissionDefinitions(), ...$record->permissionDefinitions());
            $permissions = array_map(static fn(PermissionDefinition $permission): array => [
                'code' => $permission->code(),
                'module_code' => $permission->moduleCode(),
                'name_key' => $permission->nameKey(),
            ], $catalog->all());
            $codes = array_map(static fn(PermissionDefinition $permission): string => $permission->code(), $record->permissionDefinitions());
            $originalCodes ??= $codes;
            self::assertSame($originalCodes, $codes);
            self::assertCount(12, $codes);
            self::assertSame($enabled, $record->adminMetadata()->navigationVisible());
            self::assertTrue($builder->adminMetadata()->navigationVisible());
            self::assertSame(AdminNavigationGroup::Content, $record->adminMetadata()->navigationGroup());
            self::assertSame(AdminNavigationGroup::Cms, $builder->adminMetadata()->navigationGroup());
            $router = new Router();
            (new AdminModuleRouteRegistrar($modules))->registerRoutes($router);
            $routeName = (new AdminModuleRouteResolver($modules))->managementRouteName($record->segment(), 'index');
            self::assertSame('admin.content.module.index', $routeName);
            self::assertSame('/content/' . $record->segment(), $router->url($routeName, ['module' => $record->segment()]));

            foreach (['cs', 'en'] as $locale) {
                $translator = $this->createMock(TranslatorInterface::class);
                $translator->method('locale')->willReturn($locale);
                $translator->method('get')->willReturnArgument(0);
                $factory = new EffectivePermissionGroupViewModelFactory($modules, $catalog, $translator);
                foreach ([$factory->create($permissions), $factory->createOverrideMatrix($permissions, $permissions, [])] as $projection) {
                    $groups = array_column($projection, null, 'moduleCode');
                    self::assertSame('modules', $groups[ModuleManifest::CODE]['section']);
                    self::assertSame('content-types', $groups[$record->code()]['section']);
                    self::assertSame($record->name(), $groups[$record->code()]['label']);
                    self::assertSame($record->description(), $groups[$record->code()]['description']);
                    self::assertNull($groups[$record->code()]['labelKey']);
                    self::assertNull($groups[$record->code()]['descriptionKey']);
                    self::assertSame($codes, array_column($groups[$record->code()]['permissions'], 'code'));
                }
            }
        }
    }
}
