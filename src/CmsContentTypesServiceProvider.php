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
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\ContentTypes\Actions\SetDeletedAction;
use Lemonade\Cms\ContentTypes\Actions\SetEnabledAction;
use Lemonade\Cms\ContentTypes\DataGrid\ContentTypesDataGrid;
use Lemonade\Cms\ContentTypes\Editor\ContentTypesEditor;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\Types\BooleanFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DateFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DateTimeFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\DecimalFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\IntegerFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\RichTextFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\SelectFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\TextareaFieldType;
use Lemonade\Cms\ContentTypes\Field\Types\TextFieldType;
use Lemonade\Cms\ContentTypes\Models\ContentTypeModel;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\View\ViewResourceRegistry;

/**
 * Pripojuje prehled obsahovych typu ke sdilenemu Admin transportu
 */
final readonly class CmsContentTypesServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped(Record\RecordProviderFactory::class, static fn(ContainerInterface $scope): Record\RecordProviderFactory => new Record\RecordProviderFactory($scope));
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
        Record\RecordRegistration::boot($container);
        $container->get(DataGridRegistry::class)->register(ModuleManifest::CODE, ContentTypesDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex(ModuleManifest::CODE, ContentTypesModulePageProvider::class);
    }
}
