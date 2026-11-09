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
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
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

final readonly class RecordProviderFactory implements ModuleProviderFactoryInterface
{
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
            module: $module,
            definitions: $container->get(DefinitionModel::class),
            model: $container->get(RecordModel::class),
            types: $registry,
            validation: $container->get(FieldValidation::class),
        );
        $read = new CmsContentReadModel($database, $definition);
        $lifecycle = new CmsContentLifecycle(
            definition: $definition,
            model: new CmsContentModel($database, $definition),
            mapping: new CmsDomainMapping(RecordData::class),
            routes: $container->get(CmsRouteReservationService::class),
            slugger: $container->get(Slugger::class),
            runtime: $container->get(CmsAdminRuntime::class),
            valueWriter: $values,
        );
        if ($contract === ModuleActionHandlerInterface::class) {
            return match ($action) {
                'create' => StandardEditorAction::create($container->get(EditorDispatcher::class), $moduleCode),
                'save' => StandardEditorAction::save($container->get(EditorDispatcher::class), $moduleCode),
                default => new CmsContentAction(
                    lifecycle: $lifecycle,
                    content: $read,
                    actors: $container->get(LocalActorGuard::class),
                    authorization: $container->get(AuthorizationService::class),
                    type: CmsContentActionType::from((string) $action),
                    permissionIdentity: $moduleCode,
                    translationGroup: 'records',
                ),
            };
        }
        if ($contract === EditorProviderInterface::class) {
            return new RecordEditor(
                module: $module,
                values: $values,
                content: $read,
                service: $lifecycle,
                languages: $container->get(LanguageRegistry::class),
                actors: $container->get(LocalActorGuard::class),
                validation: $container->get(RecordEditorValidationSchema::class),
                routes: $container->get(AdminModuleRouteResolver::class),
                urls: $container->get(UrlGenerator::class),
                authorization: $container->get(AuthorizationService::class),
                translator: $container->get(TranslatorInterface::class),
            );
        }
        $grid = new RecordDataGrid(
            module: $module,
            values: $values,
            types: $registry,
            content: $read,
            authorization: $container->get(AuthorizationService::class),
            translator: $container->get(TranslatorInterface::class),
            actionPresentation: $container->get(ModuleActionPresentationFactory::class),
            routes: $container->get(AdminModuleRouteResolver::class),
            urls: $container->get(UrlGenerator::class),
            languages: $container->get(LanguageRegistry::class),
            contentGrid: new CmsContentDataGrid($database, $definition),
        );
        if ($contract === DataGridProviderInterface::class) {
            return $grid;
        }
        if (in_array($contract, [ModuleIndexPageProviderInterface::class, ModuleEditorPageProviderInterface::class], true)) {
            return new RecordPageProvider(
                module: $module,
                values: $values,
                grid: $grid,
                authorization: $container->get(AuthorizationService::class),
                translator: $container->get(TranslatorInterface::class),
                urls: $container->get(UrlGenerator::class),
                routes: $container->get(AdminModuleRouteResolver::class),
                adminEditorDefinitions: new RecordEditorDefinition(
                    module: $module,
                    values: $values,
                    types: $registry,
                    urls: $container->get(UrlGenerator::class),
                    routes: $container->get(AdminModuleRouteResolver::class),
                ),
                users: $container->get(LocalUserDirectory::class),
                files: $container->get(AdminFileUploadComponent::class),
            );
        }
        throw new InvalidArgumentException('Unsupported content runtime capability.');
    }
}
