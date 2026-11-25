<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Cms\Content\CmsDisplaySettings;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorHeader;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorSaveBar;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsMediaSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsOwnershipSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsTagsField;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SeoSection;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Builds the shared editor for records of one configured content type
 */
final readonly class RecordEditorDefinition
{
    /**
     * Configures record capabilities, fields and route generation
     */
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private FieldTypeRegistry $types,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
    ) {}

    /**
     * Creates the record editor with localized metadata and custom fields
     *
     * @param array<string,mixed> $article
     * @param array<int,string> $ownerOptions
     * @param array<int,AdminFileUploadCollection> $mediaUploads
     * @param list<string> $tagOptions
     * @param array<string,mixed> $oldInput
     * @param array<string,string> $errors
     */
    public function create(
        array $article,
        string $contentLocale,
        bool $canPublish,
        bool $canAssignOwner = false,
        bool $otherPublishedLocale = false,
        array $ownerOptions = [],
        array $mediaUploads = [],
        ?AdminEditorHeaderContextDefinition $context = null,
        ?AdminFileUploadCollection $galleryUpload = null,
        ?AdminFileUploadCollection $attachmentUpload = null,
        ?AdminFileUploadCollection $thumbnailUpload = null,
        array $tagOptions = [],
        array $oldInput = [],
        array $errors = [],
    ): AdminEditorDefinition {
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
            ->header(CmsEditorHeader::create(
                module: 'records',
                indexUrl: $this->urls->route($this->routes->managementRouteName($segment, 'index'), ['module' => $segment]),
                context: $context,
                moduleName: $this->module->name(),
            ))
            ->saveBar(CmsEditorSaveBar::create());

        $translation = $article['translations'][0] ?? [];
        $publicationState = (int) ($translation['publication_state'] ?? 0);
        $title = Field::text('title', labelKey: 'records.fields.title')
            ->required()
            ->help('', 'admin.cms_validation.required_help')
            ->placeholder('', 'admin.datagrid.untitled')
            ->attributes(['maxlength' => '180'] + CmsPublicationSection::requiredAttributes($publicationState, $canPublish));
        $author = Field::text('author_name', labelKey: 'records.fields.author_name')->attributes(['maxlength' => '255']);
        $contentColumns = [
            new FieldColumn($title, md: 12),
            new FieldColumn($author, md: 12),
        ];
        if ($this->module->tagsEnabled()) {
            $contentColumns[] = CmsTagsField::column('records', $tagOptions, $translation['tags'] ?? []);
        }
        $contentBlocks = [
            Field::hidden('locale')->defaultValue($contentLocale),
            new FieldGroupBlock($contentColumns),
        ];
        $contentFields = $this->contentFields(
            articleId: (int) $article['id'],
            locale: $contentLocale,
            publicationState: $publicationState,
            canPublish: $canPublish,
            otherPublishedLocale: $otherPublishedLocale,
            mediaUploads: $mediaUploads,
            oldInput: $oldInput,
            errors: $errors,
        );
        if ($contentFields !== null) {
            $contentBlocks[] = $contentFields;
        }

        $builder->tab(new AdminEditorTab(
            id: 'content',
            label: '',
            blocks: $contentBlocks,
            labelKey: 'records.editor.tabs.content',
            default: true,
        ));
        $builder->tab(SeoSection::tab(
            pathPrefix: $this->pathPrefix((string) ($translation['canonical_path'] ?? ''), $contentLocale),
            autoGenerateUrl: trim((string) ($translation['canonical_path'] ?? '')) === '',
        ));
        $builder->tab(CmsDisplaySettings::tab());
        if ($this->module->galleryEnabled()) {
            $builder->tab(CmsMediaSection::gallery('records', $galleryUpload));
        }
        if ($this->module->attachmentsEnabled()) {
            $builder->tab(CmsMediaSection::attachments('records', $attachmentUpload));
        }

        $sidebar = [];
        $publication = CmsPublicationSection::create('records', $canPublish);
        if ($publication !== null) {
            $sidebar[] = $publication;
        }
        $sidebar[] = CmsOwnershipSection::create('records', $article, $canAssignOwner, $ownerOptions);
        $thumbnail = $this->module->thumbnailEnabled() ? CmsMediaSection::thumbnail('records', $thumbnailUpload) : null;
        if ($thumbnail !== null) {
            $sidebar[] = $thumbnail;
        }

        return $builder->sidebar(new AdminEditorSidebarDefinition($sidebar))->build();
    }

    /**
     * Returns the displayed directory for the current or next canonical URL
     */
    private function pathPrefix(string $canonicalPath, string $locale): string
    {
        $canonicalPath = trim($canonicalPath, '/');
        $prefix = $canonicalPath === '' ? $this->module->routePrefix($locale) : dirname($canonicalPath);
        if ($prefix === '.') {
            $prefix = '';
        }

        return '/' . ($prefix === '' ? '' : $prefix . '/');
    }

    /**
     * @param array<int,AdminFileUploadCollection> $mediaUploads
     * @param array<string,mixed> $oldInput
     * @param array<string,string> $errors
     */
    private function contentFields(
        int $articleId,
        string $locale,
        int $publicationState,
        bool $canPublish,
        bool $otherPublishedLocale,
        array $mediaUploads,
        array $oldInput,
        array $errors,
    ): ?SectionBlock {
        $rows = [];
        $presenceFields = [];
        $relationOptions = $this->values->relationOptionsWithInput($articleId, $locale, $oldInput);
        $repeaterItems = $this->values->repeaterEditorItems($articleId, $locale, $oldInput);
        foreach ($this->values->activeFields() as $definition) {
            if ($definition['kind'] === 'relation') {
                $fieldId = (int) $definition['id'];
                $multiple = (int) $definition['relation_multiple'] === 1;
                $required = (int) $definition['required'] === 1;
                $options = $relationOptions[$fieldId] ?? [];
                $attributes = [
                    'data-lemonade-select' => true,
                    'data-lemonade-option-source' => 'async',
                    'data-lemonade-source' => $this->urls->route('admin.cms.content-types.relation-options', [
                        'field' => $fieldId, 'locale' => $locale, 'source' => $articleId,
                    ]),
                    'data-lemonade-min-length' => '0',
                    'id' => (string) ($definition['html_id'] ?? 'field-' . $definition['code']),
                ];
                if ($multiple) {
                    $attributes['data-lemonade-ordered'] = true;
                } else {
                    $attributes['data-lemonade-clearable'] = true;
                    $options = ['' => ''] + $options;
                }
                if (($definition['css_class'] ?? '') !== '') {
                    $attributes['class'] = (string) $definition['css_class'];
                }
                if ($required) {
                    $attributes += CmsPublicationSection::requiredAttributes(
                        state: $publicationState,
                        canPublish: $canPublish,
                        globallyRequired: $otherPublishedLocale,
                        messageKey: 'content-types.validation.required',
                    );
                }
                $field = Field::select('field_' . $fieldId, (string) $definition['name'])
                    ->help((string) ($definition['help'] ?? ''))
                    ->options($options)
                    ->optionLabelKeys(['' => 'records.fields.empty_value'])
                    ->multiple($multiple)
                    ->required($required)
                    ->attributes($attributes);
                $presenceFields[] = Field::hidden('field_' . $fieldId . '_present')->defaultValue('1');
            } elseif (FieldTypeRegistry::isRepeater((string) $definition['kind'])) {
                $fieldId = (int) $definition['id'];
                $field = new CustomViewBlock('content-types::repeater-field', [
                    'definition' => $definition,
                    'items' => $repeaterItems[$fieldId] ?? [],
                    'errors' => isset($errors['field_' . $fieldId]) ? [$errors['field_' . $fieldId]] : [],
                    'moduleCode' => $this->module->code(),
                    'recordId' => $articleId,
                    'publicationState' => $publicationState,
                    'canPublish' => $canPublish,
                    'otherPublishedLocale' => $otherPublishedLocale,
                ]);
            } elseif (FieldTypeRegistry::isMedia((string) $definition['kind'])) {
                $field = new CustomViewBlock('content-types::media-field', [
                    'definition' => $definition,
                    'uploadCollection' => $mediaUploads[(int) $definition['id']] ?? null,
                ]);
            } else {
                $field = $this->types->contentField($definition);
                if ($field->isRequired()) {
                    $attributes = CmsPublicationSection::requiredAttributes(
                        state: $publicationState,
                        canPublish: $canPublish,
                        globallyRequired: (int) $definition['localized'] !== 1 && $otherPublishedLocale,
                        messageKey: 'content-types.validation.required',
                    );
                    $field = $field->attributes($attributes + $field->inputAttributes());
                }
            }
            $rows[(int) $definition['row_id']][] = new FieldColumn(
                $field,
                md: (int) $definition['span'],
            );
        }
        if ($rows === []) {
            return null;
        }

        return new SectionBlock(
            id: 'content-fields',
            blocks: [...$presenceFields, ...array_map(static fn(array $columns): FieldGroupBlock => new FieldGroupBlock($columns), array_values($rows))],
            descriptionKey: 'records.editor.required_for_publication',
        );
    }
}
