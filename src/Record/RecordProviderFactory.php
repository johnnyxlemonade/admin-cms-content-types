<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use InvalidArgumentException;
use Lemonade\Admin\Action\Contract\ModuleActionHandlerInterface;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\Action\CmsContentAction;
use Lemonade\Admin\Cms\Content\Action\CmsContentActionType;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\Contract\DataGridProviderInterface;
use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\Contract\ModuleProviderFactoryInterface;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\Content\CmsDomainMapping;
use Lemonade\Cms\Content\Models\CmsContentModel;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Support\Slug\Slugger;

/**
 * Sklada sdileny requestovy runtime pro explicitne registrovany obsahovy typ
 */
final readonly class RecordProviderFactory implements ModuleProviderFactoryInterface
{
    /**
     * Prijima scoped container bez registrace dalsich sluzeb za behu
     */
    public function __construct(private ContainerInterface $container) {}

    public function createProvider(string $moduleCode, string $contract, ?string $action = null): object
    {
        $container = $this->container;
        $module = $container->get(AdminModuleRegistry::class)->definition($moduleCode);
        if (!$module instanceof RecordModule) {
            throw new InvalidArgumentException('Not a content type runtime.');
        }
        $definition = $module->contentDefinition();
        $database = $container->get(Database::class);
        $registry = $container->get(FieldTypeRegistry::class);
        $values = new RecordValues(
            $module,
            $container->get(DefinitionModel::class),
            $container->get(RecordModel::class),
            $registry,
            $container->get(FieldValidation::class),
        );
        $read = new CmsContentReadModel($database, $definition);
        $lifecycle = new CmsContentLifecycle(
            $definition,
            new CmsContentModel($database, $definition),
            new CmsDomainMapping(RecordData::class),
            $container->get(CmsRouteReservationService::class),
            $container->get(Slugger::class),
            $container->get(CmsAdminRuntime::class),
            $values,
        );
        if ($contract === ModuleActionHandlerInterface::class) {
            return match ($action) {
                'create' => StandardEditorAction::create($container->get(EditorDispatcher::class), $moduleCode),
                'save' => StandardEditorAction::save($container->get(EditorDispatcher::class), $moduleCode),
                default => new CmsContentAction(
                    $lifecycle,
                    $read,
                    $container->get(LocalActorGuard::class),
                    $container->get(AuthorizationService::class),
                    CmsContentActionType::from((string) $action),
                    $moduleCode,
                    'records',
                ),
            };
        }
        if ($contract === EditorProviderInterface::class) {
            return new RecordEditor(
                $module,
                $values,
                $read,
                $lifecycle,
                $container->get(LanguageRegistry::class),
                $container->get(LocalActorGuard::class),
                $container->get(RecordEditorValidationSchema::class),
                $container->get(AdminModuleRouteResolver::class),
                $container->get(UrlGenerator::class),
                $container->get(AuthorizationService::class),
            );
        }
        $grid = new RecordDataGrid(
            $module,
            $values,
            $registry,
            $read,
            $container->get(AuthorizationService::class),
            $container->get(TranslatorInterface::class),
            $container->get(ModuleActionPresentationFactory::class),
            $container->get(AdminModuleRouteResolver::class),
            $container->get(UrlGenerator::class),
            $container->get(LanguageRegistry::class),
            new CmsContentDataGrid($database, $definition),
        );
        if ($contract === DataGridProviderInterface::class) {
            return $grid;
        }
        if (in_array($contract, [ModuleIndexPageProviderInterface::class, ModuleEditorPageProviderInterface::class], true)) {
            return new RecordPageProvider(
                $module,
                $values,
                $grid,
                $container->get(AuthorizationService::class),
                $container->get(TranslatorInterface::class),
                $container->get(UrlGenerator::class),
                $container->get(AdminModuleRouteResolver::class),
                new RecordEditorDefinition(
                    $module,
                    $values,
                    $registry,
                    $container->get(UrlGenerator::class),
                    $container->get(AdminModuleRouteResolver::class),
                ),
                $container->get(LocalUserDirectory::class),
            );
        }
        throw new InvalidArgumentException('Unsupported content runtime capability.');
    }
}
