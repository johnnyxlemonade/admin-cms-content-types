<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DateTimeImmutable;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionDefinition;
use Lemonade\Admin\DataGrid\Cell\ScalarCell;
use Lemonade\Admin\DataGrid\Cell\StackedCell;
use Lemonade\Admin\DataGrid\Cell\StatusCell;
use Lemonade\Admin\DataGrid\Cell\StatusVariant;
use Lemonade\Admin\DataGrid\Contract\DataGridProviderInterface;
use Lemonade\Admin\DataGrid\DataGridColumnDefinition;
use Lemonade\Admin\DataGrid\DataGridDefinition;
use Lemonade\Admin\DataGrid\DataGridFilterDefinition;
use Lemonade\Admin\DataGrid\DataGridResult;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Select\SelectOptionDefinition;
use Lemonade\Admin\Select\StaticSelectOptionSource;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Models\RecordGridProjection;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Promita obsahove zaznamy do sdileneho DataGridu bez dotazu pro jednotlive radky
 */
final readonly class RecordDataGrid implements DataGridProviderInterface
{
    /**
     * Pripojuje CMS projekci, definice poli a autorizaci DataGridu
     */
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private FieldTypeRegistry $types,
        private CmsContentReadModel $content,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private ModuleActionPresentationFactory $actionPresentation,
        private AdminModuleRouteResolver $routes,
        private UrlGenerator $urls,
        private LanguageRegistry $languages,
        private CmsContentDataGrid $contentGrid,
    ) {}

    /**
     * Vrati pravo potrebne pro nacteni prehledu zaznamu
     */
    public function permission(): string
    {
        return $this->module->code() . '.view_own';
    }

    /**
     * Deklaruje sloupce, search, filtry a deterministic razeni prehledu
     */
    public function dataGridDefinition(): DataGridDefinition
    {
        return new DataGridDefinition(
            id: $this->module->code(),
            columns: [
                new DataGridColumnDefinition('title', 'records.fields.title', 'title'),
                new DataGridColumnDefinition('state', 'records.fields.publication_state', 'state'),
                new DataGridColumnDefinition('publishedAt', 'records.fields.published_at', 'publishedAt', configurable: true),
                new DataGridColumnDefinition(
                    'owner',
                    'records.fields.owner',
                    class: 'd-none d-xl-table-cell',
                    configurable: true,
                    visibleByDefault: false,
                ),
                new DataGridColumnDefinition('createdAt', 'records.fields.created_at', configurable: true, visibleByDefault: false),
                new DataGridColumnDefinition(
                    'updatedAt',
                    'records.fields.updated_at',
                    'updatedAt',
                    'd-none d-xl-table-cell',
                    configurable: true,
                    visibleByDefault: false,
                ),
                new DataGridColumnDefinition('deletedAt', 'records.fields.deleted_at', configurable: true, visibleByDefault: false),
                ...$this->customColumns(),
                new DataGridColumnDefinition('actions', 'admin.common.actions'),
            ],
            searchEnabled: true,
            filters: [
                ...$this->customFilters(),
                new DataGridFilterDefinition(
                    key: 'status',
                    optionSource: new StaticSelectOptionSource(
                        options: [
                            new SelectOptionDefinition('active', $this->translator->get('records.list.active')),
                            new SelectOptionDefinition('deleted', $this->translator->get('records.list.deleted')),
                        ],
                    ),
                ),
                new DataGridFilterDefinition('state', new StaticSelectOptionSource([
                    new SelectOptionDefinition('new', $this->translator->get('admin.status.new')),
                    new SelectOptionDefinition('draft', $this->translator->get('admin.status.draft')),
                    new SelectOptionDefinition('published', $this->translator->get('admin.status.published')),
                ])),
                new DataGridFilterDefinition('locale', new StaticSelectOptionSource(array_map(
                    fn(array $language): SelectOptionDefinition => new SelectOptionDefinition($language['code'], $language['name']),
                    $this->content->dataGridLocales(),
                ))),
            ],
            defaultSortKey: 'publishedAt',
            defaultSortDirection: 'desc',
            defaultPageSize: 25,
            maximumPageSize: 100,
            defaultView: 'active',
            showAllView: false,
        );
    }

    /**
     * Vrati typed radky z jedine batch projekce obsahu modelu
     */
    public function execute(DataGridQuery $query): DataGridResult
    {
        $canViewAll = $this->authorization->hasPermission($this->module->code() . '.view');
        $ownerId = $canViewAll ? null : $this->authorization->currentUserId();
        if (!$canViewAll && $ownerId === null) {
            return new DataGridResult([], $query->page(), $query->pageSize(), 0);
        }
        $page = $this->contentGrid->listForDataGrid(
            $query,
            $this->languages->defaultLocale(),
            $ownerId,
            projection: new RecordGridProjection($this->values->activeFields(), $this->types),
        );
        $rows = array_map($this->row(...), $page->items());

        return new DataGridResult($rows, $page->page(), $page->perPage(), $page->total());
    }

    /**
     * Vytvori standardni radek s auditnimi udaji a autorizovanymi akcemi
     *
     * @param array<string,mixed> $article
     */
    private function row(array $article): DataGridRowDefinition
    {
        $articleId = (int) $article['id'];
        $locale = (string) $article['locale'];
        $title = trim((string) ($article['title'] ?? ''));
        $displayTitle = $title === ''
            ? $this->translator->get('admin.datagrid.untitled')
            : $title;
        $deleted = $article['deleted_at'] !== null;
        $ownerId = $article['owner_id'] === null ? null : (int) $article['owner_id'];
        $editable = !$deleted && $this->authorization->canForOwner(
            $this->module->code() . '.edit',
            $this->module->code() . '.edit_own',
            $ownerId,
        );

        return new DataGridRowDefinition(
            id: $articleId,
            cells: [
                ...$this->customCells($article),
                'title' => new StackedCell(
                    value: $displayTitle,
                    secondary: (string) ($article['canonical_path'] ?? ''),
                    url: $editable ? $this->editUrl($articleId, $locale) : null,
                ),
                'state' => new StatusCell(
                    $this->publicationStateLabel((int) $article['publication_state']),
                    (int) $article['publication_state'] === CmsPublicationState::Published->value ? StatusVariant::Success : StatusVariant::Muted,
                ),
                'publishedAt' => new ScalarCell($this->formatDateTime($article['published_at'] ?? null)),
                'owner' => new ScalarCell((string) ($article['owner_name'] ?? '—')),
                'createdAt' => $this->auditMetadataCell($article['created_at'] ?? null, $article['created_by_name'] ?? null),
                'updatedAt' => $this->auditMetadataCell($article['updated_at'] ?? null, $article['updated_by_name'] ?? null),
                'deletedAt' => $this->auditMetadataCell($article['deleted_at'] ?? null, $article['deleted_by_name'] ?? null),
            ],
            actions: $this->rowActions($articleId, $deleted, $locale, $ownerId),
        );
    }

    /**
     * Vrati lokalizovany label publication stavu z databazove hodnoty
     */
    private function publicationStateLabel(int $value): string
    {
        $state = CmsPublicationState::tryFrom($value) ?? CmsPublicationState::New;

        return $this->translator->get('admin.status.' . match ($state) {
            CmsPublicationState::New => 'new',
            CmsPublicationState::Draft => 'draft',
            CmsPublicationState::Published => 'published',
        });
    }

    /**
     * Nabizi editaci pouze jako neautoritativni presentation akci
     *
     * @return list<DataGridRowActionDefinition>
     */
    private function rowActions(
        int $articleId,
        bool $deleted,
        string $contentLocale,
        ?int $ownerId,
    ): array {
        $actions = [];
        if (!$deleted && $this->authorization->canForOwner($this->module->code() . '.edit', $this->module->code() . '.edit_own', $ownerId)) {
            $actions[] = (new DataGridRowActionDefinition(
                key: 'edit',
                label: $this->translator->get('admin.common.edit'),
                url: $this->editUrl($articleId, $contentLocale),
                method: 'GET',
            ))->withIcon(AdminIcon::PencilSquare);
        }
        $canChangeLifecycle = $deleted
            ? $this->authorization->canForOwner($this->module->code() . '.restore', $this->module->code() . '.restore_own', $ownerId)
            : $this->authorization->canForOwner($this->module->code() . '.delete', $this->module->code() . '.delete_own', $ownerId);
        if ($canChangeLifecycle) {
            $action = $this->actionPresentation->rowAction(
                moduleCode: $this->module->code(),
                action: $deleted ? 'restore' : 'delete',
                entityId: $articleId,
            );
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Zobrazi auditni datum nad identitou uzivatele
     */
    private function auditMetadataCell(mixed $date, mixed $actor): StackedCell
    {
        $date = trim((string) $date);
        $actor = trim((string) $actor);

        return new StackedCell(
            value: $this->formatDateTime($date),
            secondary: $date === '' || $actor === '' ? '—' : $actor,
        );
    }

    /**
     * Zobrazi datum bez sekund podle jazyka administrace
     */
    private function formatDateTime(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '—';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        if ($date === false) {
            return $text;
        }

        return $date->format(match ($this->translator->locale()) {
            'en' => 'M j, Y H:i',
            default => 'd. m. Y H:i',
        });
    }

    /**
     * Sklada canonical URL editoru s explicitni content locale mutace
     */
    private function editUrl(int $articleId, ?string $contentLocale = null): string
    {
        $url = $this->urls->route(
            $this->routes->managementRouteName($this->module->segment(), 'edit'),
            ['module' => $this->module->segment(), 'id' => $articleId],
        );

        return $contentLocale === null ? $url : $url . '?locale=' . rawurlencode($contentLocale);
    }

    /**
     * Nabizi vlastni sloupce ve standardnim vyberu viditelnosti
     *
     * @return list<DataGridColumnDefinition>
     */
    private function customColumns(): array
    {
        return array_map(static fn(array $field): DataGridColumnDefinition => new DataGridColumnDefinition(
            'field_' . $field['id'],
            '',
            $field['kind'] === 'richtext' ? null : 'field_' . $field['id'],
            configurable: true,
            visibleByDefault: false,
            label: (string) $field['name'],
        ), $this->values->activeFields());
    }

    /**
     * Nabizi jednoznacne SQL filtry bool a Select bez obecneho query jazyka
     *
     * @return list<DataGridFilterDefinition>
     */
    private function customFilters(): array
    {
        $filters = [];
        foreach ($this->values->activeFields() as $field) {
            $storage = $this->types->get((string) $field['kind'])->storage();
            $options = [];
            if ($storage === 'boolean') {
                foreach (['0' => 'no', '1' => 'yes'] as $value => $key) {
                    $options[] = new SelectOptionDefinition((string) $value, $field['name'] . ': ' . $this->translator->get('content-types.editor.' . $key));
                }
            } elseif ($storage === 'option') {
                foreach ($field['options'] as $option) {
                    if ($option['archived_at'] === null) {
                        $options[] = new SelectOptionDefinition((string) $option['code'], $field['name'] . ': ' . $option['name']);
                    }
                }
            }
            if (isset(RecordGridProjection::FILTERS[$storage])) {
                [$inputType, $pattern] = RecordGridProjection::FILTERS[$storage];
                $filters[] = new DataGridFilterDefinition(
                    'field_' . $field['id'],
                    new StaticSelectOptionSource([]),
                    label: (string) $field['name'],
                    inputType: $inputType,
                    valuePattern: $pattern,
                );
            }
            if ($options !== []) {
                $filters[] = new DataGridFilterDefinition(
                    'field_' . $field['id'],
                    new StaticSelectOptionSource($options),
                    label: (string) $field['name'],
                    allLabelKey: 'records.filters.all_values',
                );
            }
        }
        return $filters;
    }

    /**
     * Promita vlastni hodnoty jako escapovane scalar bunky vcetne Select nazvu
     *
     * @param array<string,mixed> $row
     * @return array<string,ScalarCell>
     */
    private function customCells(array $row): array
    {
        $cells = [];
        foreach ($this->values->activeFields() as $field) {
            $key = 'field_' . $field['id'];
            $value = $row[$key] ?? null;
            $storage = $this->types->get((string) $field['kind'])->storage();
            if ($storage === 'option' && $value !== null) {
                $value = array_column($field['options'], 'name', 'id')[$value] ?? '';
            } elseif ($storage === 'boolean' && $value !== null) {
                $value = $this->translator->get('content-types.editor.' . ((int) $value === 1 ? 'yes' : 'no'));
            }
            $cells[$key] = new ScalarCell($value === null ? '—' : strip_tags((string) $value));
        }
        return $cells;
    }
}
