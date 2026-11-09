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
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Database\Config\DatabaseRuntimeConfig;
use Lemonade\Framework\Database\DatabaseDriverInterface;

final readonly class RecordRegistration
{
    public static function boot(ContainerInterface $container): void
    {
        if (!$container->has(DatabaseDriverInterface::class)) {
            return;
        }
        if ($container->has(DatabaseRuntimeConfig::class)
            && $container->get(DatabaseRuntimeConfig::class)->connections === []) {
            return;
        }
        foreach ($container->get(RecordModel::class)->modules() as $module) {
            $code = $module->code();
            foreach ($container->get(DefinitionModel::class)->fields($module->id()) as $field) {
                $usage = $container->get(FieldTypeRegistry::class)->mediaDefinition($code, $field);
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
