<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes;

use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\EditorLoaded;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Admin\Page\ModulePage;
use Lemonade\Cms\ContentTypes\DataGrid\ContentTypesDataGrid;
use Lemonade\Cms\ContentTypes\Editor\ContentTypesEditorDefinition;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

final readonly class ContentTypesModulePageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface
{
    public function __construct(
        private ContentTypesDataGrid $grid,
        private TranslatorInterface $translator,
        private UrlGenerator $urls,
        private AuthorizationService $authorization,
        private ContentTypesEditorDefinition $definitions,
    ) {}

    public function indexPermission(): string
    {
        return 'cms.content-types.view';
    }

    public function index(string $locale): ModulePage
    {
        $viewModel = new DataGridIndexViewModel(
            moduleCode: 'content-types',
            title: $this->translator->get('content-types.list.title'),
            description: $this->translator->get('content-types.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => 'content-types'],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $this->authorization->hasPermission(ModuleManifest::CODE . '.manage_definitions')
                ? new DataGridPrimaryAction(translationKey: 'content-types.actions.create', icon: AdminIcon::PlusLg, href: $this->urls->route('admin.cms.module.create', ['module' => 'content-types']))
                : null,
            loadingText: $this->translator->get('content-types.list.loading'),
            emptyText: $this->translator->get('content-types.list.empty'),
            errorText: $this->translator->get('admin.datagrid.errorDescription'),
        );

        return new ModulePage(
            view: 'admin::datagrid.index',
            title: $viewModel->title(),
            data: ['dataGrid' => $viewModel, 'locale' => $locale],
        );
    }

    public function create(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        return $this->editor($editor, $errors, $input, $locale, $query);
    }

    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        /** @var array<string,mixed> $type */
        $type = $editor->data()['type'];

        return new ModulePage('content-types::editor', $this->translator->get('content-types.editor.title'), [
            'adminEditor' => $this->definitions->create($type),
            'adminEditorContext' => new AdminEditorRenderContext(values: $type, oldInput: $input, errors: $errors, mode: isset($type['id']) ? 'edit' : 'create'),
            'locale' => $locale,
        ]);
    }
}
