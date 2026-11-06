<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorHeader;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorSaveBar;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsOwnershipSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SeoSection;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sestavuje standardni CMS editor z relacni definice bez noveho rendereru
 */
final readonly class RecordEditorDefinition
{
    /**
     * Pripojuje existujici controls, layout a canonical routy
     */
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private FieldTypeRegistry $types,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
    ) {}

    /**
     * Sklada obsah, SEO, publikaci a vlastnictvi ve sdilenem AdminEditoru
     *
     * @param array<string,mixed> $article
     * @param array<int,string> $ownerOptions
     */
    public function create(array $article, string $contentLocale, bool $canPublish, bool $canAssignOwner = false, array $ownerOptions = [], ?AdminEditorHeaderContextDefinition $context = null, bool $otherPublishedLocale = false): AdminEditorDefinition
    {
        $segment = $this->module->segment();
        $params = ['module' => $segment, 'id' => (int) $article['id'], 'locale' => $contentLocale];
        $builder = AdminEditorBuilder::create($this->module->code())
            ->form(new AdminActionFormDefinition(
                id: 'content-record-editor',
                action: $this->urls->route($this->routes->managementRouteName($segment, 'update'), $params),
                actionUrl: $this->urls->route($this->routes->managementRouteName($segment, 'ajax.entity'), $params),
                actionKey: 'save',
                novalidate: true,
            ))
            ->header(CmsEditorHeader::create('records', $this->urls->route($this->routes->managementRouteName($segment, 'index'), ['module' => $segment]), $context))
            ->saveBar(CmsEditorSaveBar::create());
        $blocks = [
            Field::hidden('locale')->defaultValue($contentLocale),
            new FieldGroupBlock([
                new FieldColumn(Field::text('title', labelKey: 'records.fields.title')->required()->attributes(['maxlength' => '180']), md: 12),
                new FieldColumn(Field::text('author_name', labelKey: 'records.fields.author_name')->attributes(['maxlength' => '255']), md: 12),
            ]),
        ];
        $rows = [];
        foreach ($this->values->activeFields() as $definition) {
            $field = $this->types->contentField($definition);
            if ($field->isRequired()) {
                $globalRequired = (int) $definition['localized'] !== 1 && $otherPublishedLocale;
                $published = (int) ($article['translations'][0]['publication_state'] ?? 0) === CmsPublicationState::Published->value;
                $attributes = [
                    'data-lemonade-required-message' => 'content-types.validation.required',
                    'data-lemonade-required-active' => $globalRequired || $published ? '1' : '0',
                ];
                if ($canPublish && !$globalRequired) {
                    $attributes['data-lemonade-required-when'] = 'publication_state';
                    $attributes['data-lemonade-required-value'] = (string) CmsPublicationState::Published->value;
                }
                $field = $field->attributes($attributes + $field->inputAttributes());
            }
            $rows[(int) $definition['row_id']][] = new FieldColumn($field, md: (int) $definition['span']);
        }
        if ($rows !== []) {
            $blocks[] = new SectionBlock(
                id: 'content-fields',
                blocks: array_map(static fn(array $columns): FieldGroupBlock => new FieldGroupBlock($columns), array_values($rows)),
                descriptionKey: 'records.editor.required_for_publication',
            );
        }
        $builder->tab(new AdminEditorTab('content', '', $blocks, labelKey: 'records.editor.tabs.content', default: true))
            ->tab(SeoSection::tab(pathPrefix: '/', autoGenerateUrl: trim((string) ($article['translations'][0]['canonical_path'] ?? '')) === ''));
        $sidebar = [];
        $publication = CmsPublicationSection::create('records', $canPublish);
        if ($publication !== null) {
            $sidebar[] = $publication;
        }
        $sidebar[] = CmsOwnershipSection::create('records', $article, $canAssignOwner, $ownerOptions);
        return $builder->sidebar(new AdminEditorSidebarDefinition($sidebar))->build();
    }
}
