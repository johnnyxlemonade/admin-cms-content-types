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
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsMediaSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsOwnershipSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SeoSection;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Routing\UrlGenerator;

final readonly class RecordEditorDefinition
{
    public function __construct(
        private RecordModule $module,
        private RecordValues $values,
        private FieldTypeRegistry $types,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
    ) {}

    /**
     * @param array<string,mixed> $article
     * @param array<int,string> $ownerOptions
     * @param array<int,AdminFileUploadCollection> $mediaUploads
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
        $contentBlocks = [
            Field::hidden('locale')->defaultValue($contentLocale),
            new FieldGroupBlock([
                new FieldColumn($title, md: 12),
                new FieldColumn($author, md: 12),
            ]),
        ];
        $contentFields = $this->contentFields(
            publicationState: $publicationState,
            canPublish: $canPublish,
            otherPublishedLocale: $otherPublishedLocale,
            mediaUploads: $mediaUploads,
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
            pathPrefix: '/',
            autoGenerateUrl: trim((string) ($translation['canonical_path'] ?? '')) === '',
        ));
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
     * @param array<int,AdminFileUploadCollection> $mediaUploads
     */
    private function contentFields(
        int $publicationState,
        bool $canPublish,
        bool $otherPublishedLocale,
        array $mediaUploads,
    ): ?SectionBlock {
        $rows = [];
        foreach ($this->values->activeFields() as $definition) {
            if ($this->types->get((string) $definition['kind'])->storage() === null) {
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
            blocks: array_map(static fn(array $columns): FieldGroupBlock => new FieldGroupBlock($columns), array_values($rows)),
            descriptionKey: 'records.editor.required_for_publication',
        );
    }
}
