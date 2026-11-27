<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes;

use Lemonade\Admin\Action\ModuleActionDefinition;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ConfirmationDefinition;
use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Module\AdminModuleContributionLoader;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Routing\AdminRouteRegistrarRegistry;
use Lemonade\Cms\ContentTypes\Actions\SetDeletedAction;
use Lemonade\Cms\ContentTypes\Actions\SetEnabledAction;
use Lemonade\Cms\ContentTypes\DataGrid\ContentTypesDataGrid;
use Lemonade\Cms\ContentTypes\Editor\ContentTypesEditor;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\Types\BooleanFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DateFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DateTimeFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DecimalFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\FileFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\FilesFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\GalleryFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\ImageFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\IntegerFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\RelationFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\RepeaterFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\RichTextFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\SelectFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\TextareaFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\TextFieldType;
use Lemonade\Cms\ContentTypes\Models\ContentTypeModel;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\Public\PublishedRecordReadModel;
use Lemonade\Cms\ContentTypes\Record\ContentTypeBootstrapSnapshot;
use Lemonade\Cms\ContentTypes\Record\RecordProviderFactory;
use Lemonade\Cms\ContentTypes\Record\RecordRegistration;
use Lemonade\Cms\ContentTypes\Record\RelationOptionsController;
use Lemonade\Cms\ContentTypes\Record\RelationOptionsRouteRegistrar;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Database\Config\DatabaseRuntimeConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\View\ViewResourceRegistry;

/**
 * Registers Content Types services and runtime integrations
 */
final readonly class CmsContentTypesServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped(RecordProviderFactory::class, static fn(ContainerInterface $scope): RecordProviderFactory => new RecordProviderFactory($scope));
        $container->scoped(RelationOptionsController::class, RelationOptionsController::class);
        $container->scoped(PublishedRecordReadModel::class, PublishedRecordReadModel::class);
        $container->scoped(ContentTypeBootstrapSnapshot::class, self::snapshot(...));
        $container->scoped(DefinitionModel::class, fn(ContainerInterface $scope): DefinitionModel => new DefinitionModel(
            $scope->get(Database::class),
            $scope->get(ContentTypeBootstrapSnapshot::class)->fields(),
        ));
        $container->scoped(RecordModel::class, fn(ContainerInterface $scope): RecordModel => new RecordModel(
            $scope->get(Database::class),
            $scope->get(FieldTypeRegistry::class),
            $scope->get(ContentTypeBootstrapSnapshot::class)->modules(),
        ));
        $container->singleton(RelationOptionsRouteRegistrar::class, RelationOptionsRouteRegistrar::class);
        $container->singleton(FieldTypeRegistry::class, static fn(): FieldTypeRegistry => new FieldTypeRegistry([
            new TextFieldType(),
            new TextareaFieldType(),
            new RichTextFieldType(),
            new IntegerFieldType(),
            new DecimalFieldType(),
            new BooleanFieldType(),
            new DateFieldType(),
            new DateTimeFieldType(),
            new SelectFieldType(),
            new ImageFieldType(),
            new GalleryFieldType(),
            new FileFieldType(),
            new FilesFieldType(),
            new RelationFieldType(),
            new RepeaterFieldType(),
        ]));
        $container->scoped('content-types.create', static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::create($container->get(EditorDispatcher::class), ModuleManifest::CODE));
        $container->scoped('content-types.save', static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::save($container->get(EditorDispatcher::class), ModuleManifest::CODE));
        $container->scoped('content-types.enable', static fn(ContainerInterface $container): SetEnabledAction => new SetEnabledAction($container->get(DefinitionService::class), true));
        $container->scoped('content-types.disable', static fn(ContainerInterface $container): SetEnabledAction => new SetEnabledAction($container->get(DefinitionService::class), false));
        $container->scoped('content-types.delete', static fn(ContainerInterface $container): SetDeletedAction => new SetDeletedAction($container->get(DefinitionService::class), true));
        $container->scoped('content-types.restore', static fn(ContainerInterface $container): SetDeletedAction => new SetDeletedAction($container->get(DefinitionService::class), false));
        $container->scoped(ContentTypesEditor::class, ContentTypesEditor::class);
        $container->scoped(ContentTypeModel::class, ContentTypeModel::class);
        $container->scoped(ContentTypesDataGrid::class, ContentTypesDataGrid::class);
        $container->scoped(ContentTypesModulePageProvider::class, ContentTypesModulePageProvider::class);
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(TranslationResourceRegistry::class)->register(__DIR__ . '/Resources/lang', ModuleManifest::CODE);
        $container->get(ViewResourceRegistry::class)->register('content-types', __DIR__ . '/Resources/views');
        $container->get(AdminRouteRegistrarRegistry::class)->register($container->get(RelationOptionsRouteRegistrar::class));
        $container->get(EditorRegistry::class)->register(ModuleManifest::CODE, ContentTypesEditor::class);
        $container->get(ModulePageRegistry::class)->registerEditor(ModuleManifest::CODE, ContentTypesModulePageProvider::class);
        foreach (['create', 'save'] as $action) {
            $container->get(ModuleActionRegistry::class)->register(ModuleManifest::CODE, new ModuleActionDefinition($action, ModuleManifest::CODE . '.manage_definitions', 'content-types.actions.' . $action), 'content-types.' . $action);
        }
        foreach (['enable', 'disable', 'delete', 'restore'] as $action) {
            $container->get(ModuleActionRegistry::class)->register(ModuleManifest::CODE, new ModuleActionDefinition(
                key: $action,
                permission: ModuleManifest::CODE . '.manage_definitions',
                labelKey: 'content-types.actions.' . $action,
                confirmation: new ConfirmationDefinition(
                    messageKey: 'content-types.confirm.' . $action,
                    titleKey: 'content-types.actions.' . $action,
                ),
                refreshGrid: true,
            ), 'content-types.' . $action);
        }
        $container->get(ClientTranslationGroupRegistry::class)->register('content-types');
        $container->get(ClientTranslationGroupRegistry::class)->register('records');
        $container->get(AdminModuleContributionLoader::class)->defer(
            static function (?ContainerInterface $scope) use ($container): void {
                $runtime = $scope ?? $container;
                $snapshot = $scope === null ? self::snapshot($runtime) : $scope->get(ContentTypeBootstrapSnapshot::class);
                RecordRegistration::register($runtime, $snapshot->modules(), $snapshot->fields());
            },
        );
        $container->get(DataGridRegistry::class)->register(ModuleManifest::CODE, ContentTypesDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex(ModuleManifest::CODE, ContentTypesModulePageProvider::class);
    }

    /**
     * Creates one lazy catalog shared by models and Admin contributions within a runtime scope
     */
    private static function snapshot(ContainerInterface $container): ContentTypeBootstrapSnapshot
    {
        $snapshot = new ContentTypeBootstrapSnapshot();
        $snapshot->defer(static function () use ($container): array {
            if (!$container->has(DatabaseDriverInterface::class)) {
                return ['modules' => [], 'fields' => []];
            }
            if ($container->has(DatabaseRuntimeConfig::class)
                && $container->get(DatabaseRuntimeConfig::class)->connections === []) {
                return ['modules' => [], 'fields' => []];
            }
            $database = $container->get(Database::class);
            $fieldTypes = $container->get(FieldTypeRegistry::class);
            $modules = (new RecordModel($database, $fieldTypes))->modules();

            return [
                'modules' => $modules,
                'fields' => (new DefinitionModel($database))->fieldsForTypes(array_map(
                    static fn($module): int => $module->id(),
                    $modules,
                )),
            ];
        });

        return $snapshot;
    }
}
