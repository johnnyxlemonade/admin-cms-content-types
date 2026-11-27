<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\Cms\Content\CmsContentAdminProvider;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Container\ContainerInterface;

final readonly class RecordRegistration
{
    /**
     * Registers one already loaded dynamic Content Type catalog with Admin infrastructure
     *
     * @param list<RecordModule> $modules
     * @param array<int,list<array<string,mixed>>> $fields
     */
    public static function register(ContainerInterface $container, array $modules, array $fields): void
    {
        $fieldTypes = $container->get(FieldTypeRegistry::class);
        foreach ($modules as $module) {
            $code = $module->code();
            $container->get(AdminFileUsageRegistry::class)->registerCapabilities(
                $code,
                $module->fileCapabilities(),
            );
            foreach ($fields[$module->id()] ?? [] as $field) {
                $usage = $fieldTypes->mediaDefinition($code, $field);
                if ($usage !== null) {
                    $container->get(AdminFileUsageRegistry::class)->register($usage);
                }
            }
            $factory = RecordProviderFactory::class;
            $container->get(AdminModuleRegistry::class)->register($module);
            $container->get(PermissionCatalogRegistry::class)->register(...$module->permissionDefinitions());
            $container->get(AuditEventPresentationRegistry::class)->registerModuleDefinition($module);
            $container->get(DataGridRegistry::class)->register($code, $factory);
            $container->get(EditorRegistry::class)->register($code, $factory);
            $container->get(ModulePageRegistry::class)->registerIndex($code, $factory);
            $container->get(ModulePageRegistry::class)->registerEditor($code, $factory);
            $provider = new CmsContentAdminProvider(
                moduleCode: $code,
                translationGroup: 'records',
                translationPath: dirname(__DIR__) . '/Resources/lang',
                dataGridService: $factory,
                pageService: $factory,
                editorService: $factory,
                lifecycleService: $factory,
                contentDefinitionService: $factory,
            );
            $provider->registerActions($container->get(ModuleActionRegistry::class), $factory);
        }
    }
}
