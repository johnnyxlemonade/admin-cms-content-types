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
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Database\Config\DatabaseRuntimeConfig;
use Lemonade\Framework\Database\DatabaseDriverInterface;

/**
 * Pripojuje ulozene definice do existujicich Admin registru bez DDL a zapisu prav
 */
final readonly class RecordRegistration
{
    /**
     * Registruje databazove prispevky jen po instalaci schema
     */
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
            $factory = RecordProviderFactory::class;
            $container->get(AdminModuleRegistry::class)->register($module);
            $container->get(PermissionCatalogRegistry::class)->register(...$module->permissionDefinitions());
            $container->get(AuditEventPresentationRegistry::class)->registerModuleDefinition($module);
            $container->get(DataGridRegistry::class)->register($code, $factory);
            $container->get(EditorRegistry::class)->register($code, $factory);
            $container->get(ModulePageRegistry::class)->registerIndex($code, $factory);
            $container->get(ModulePageRegistry::class)->registerEditor($code, $factory);
            $provider = new CmsContentAdminProvider(
                $code,
                'records',
                dirname(__DIR__) . '/Resources/lang',
                $factory,
                $factory,
                $factory,
                $factory,
                $factory,
            );
            $provider->registerActions($container->get(ModuleActionRegistry::class), $factory);
        }
    }
}
