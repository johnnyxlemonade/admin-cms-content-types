<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\DataGrid;

use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionDefinition;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionKind;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionPlacement;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionRisk;
use Lemonade\Admin\DataGrid\Cell\LinkCell;
use Lemonade\Admin\DataGrid\Cell\ScalarCell;
use Lemonade\Admin\DataGrid\Contract\DataGridProviderInterface;
use Lemonade\Admin\DataGrid\DataGridColumnDefinition;
use Lemonade\Admin\DataGrid\DataGridDefinition;
use Lemonade\Admin\DataGrid\DataGridFilterDefinition;
use Lemonade\Admin\DataGrid\DataGridResult;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Select\SelectOptionDefinition;
use Lemonade\Admin\Select\StaticSelectOptionSource;
use Lemonade\Cms\ContentTypes\Models\ContentTypeModel;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

final readonly class ContentTypesDataGrid implements DataGridProviderInterface
{
    public function __construct(
        private ContentTypeModel $types,
        private AuthorizationService $authorization,
        private UrlGenerator $urls,
        private TranslatorInterface $translator,
        private ModuleActionPresentationFactory $actions,
    ) {}

    public function permission(): string
    {
        return ModuleManifest::CODE . '.view';
    }

    public function dataGridDefinition(): DataGridDefinition
    {
        return new DataGridDefinition(
            id: 'content-types',
            columns: [
                new DataGridColumnDefinition(key: 'name', translationKey: 'content-types.fields.name', sortKey: 'name'),
                new DataGridColumnDefinition(key: 'code', translationKey: 'content-types.fields.code', sortKey: 'code'),
                new DataGridColumnDefinition(key: 'enabled', translationKey: 'content-types.fields.enabled'),
                new DataGridColumnDefinition(key: 'actions', translationKey: 'admin.common.actions'),
            ],
            searchEnabled: true,
            filters: [
                new DataGridFilterDefinition('status', new StaticSelectOptionSource([
                    new SelectOptionDefinition('active', $this->translator->get('content-types.list.active')),
                    new SelectOptionDefinition('deleted', $this->translator->get('content-types.list.deleted')),
                ])),
            ],
            defaultSortKey: 'name',
            defaultSortDirection: 'asc',
            defaultPageSize: 25,
            maximumPageSize: 100,
            defaultView: 'active',
            showAllView: false,
        );
    }

    public function execute(DataGridQuery $query): DataGridResult
    {
        $page = $this->types->listForDataGrid($query);

        return new DataGridResult(
            items: array_map($this->row(...), $page->items()),
            page: $page->page(),
            perPage: $page->perPage(),
            total: $page->total(),
        );
    }

    /**
     * @param array{id:int,code:string,name:string,enabled:int,deleted_at:string|null} $row
     */
    private function row(array $row): DataGridRowDefinition
    {
        $canManage = $this->authorization->hasPermission(ModuleManifest::CODE . '.manage_definitions');
        $deleted = $row['deleted_at'] !== null;
        $actions = [];
        $editUrl = null;
        if ($canManage && !$deleted) {
            $editUrl = $this->urls->route('admin.cms.module.edit', ['module' => 'content-types', 'id' => $row['id']]);
            $actions[] = new DataGridRowActionDefinition(
                key: 'edit',
                label: $this->translator->get('content-types.actions.edit'),
                url: $editUrl,
                method: 'GET',
                kind: DataGridRowActionKind::Navigate,
                placement: DataGridRowActionPlacement::Secondary,
                icon: AdminIcon::PencilSquare,
            );
            $action = $this->actions->rowAction(ModuleManifest::CODE, (int) $row['enabled'] === 1 ? 'disable' : 'enable', (int) $row['id']);
            if ($action !== null) {
                $actions[] = $action;
            }
        }
        if ($canManage) {
            $action = $this->actions->rowAction(
                ModuleManifest::CODE,
                $deleted ? 'restore' : 'delete',
                (int) $row['id'],
                placement: $deleted ? DataGridRowActionPlacement::Secondary : DataGridRowActionPlacement::Destructive,
                risk: $deleted ? DataGridRowActionRisk::Normal : DataGridRowActionRisk::Destructive,
            );
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return new DataGridRowDefinition(
            id: (int) $row['id'],
            cells: [
                'name' => $editUrl === null ? new ScalarCell(value: $row['name']) : new LinkCell(value: $row['name'], url: $editUrl),
                'code' => new ScalarCell(value: $row['code']),
                'enabled' => new ScalarCell(value: $this->translator->get((int) $row['enabled'] === 1 ? 'content-types.editor.yes' : 'content-types.editor.no')),
            ],
            actions: $actions,
        );
    }
}
