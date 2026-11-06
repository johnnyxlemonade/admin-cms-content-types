<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Editor;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorSaveBar;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada AdminEditor a sablony inspectoru bez vlastniho formularoveho rendereru
 */
final readonly class ContentTypesEditorDefinition
{
    /**
     * Nastavuje named routy a lokalizovane moznosti controls
     */
    public function __construct(
        private UrlGenerator $urls,
        private FieldTypeRegistry $types,
        private AdminEditorFieldRenderer $fields,
    ) {}

    /**
     * Sestavi editor typu se sdilenym vizualnim editorem definic
     *
     * @param array<string,mixed> $type
     */
    public function create(array $type): AdminEditorDefinition
    {
        $id = isset($type['id']) ? (int) $type['id'] : null;
        $params = ['module' => 'content-types'];
        if ($id !== null) {
            $params['id'] = $id;
        }
        $form = new AdminActionFormDefinition(
            id: 'content-types-editor',
            action: $this->urls->route($id === null ? 'admin.cms.module.create' : 'admin.cms.module.edit', $params),
            actionUrl: $this->urls->route($id === null ? 'admin.cms.module.ajax.create' : 'admin.cms.module.ajax.entity', $params),
            actionKey: $id === null ? 'create' : 'save',
            novalidate: true,
            navigateToEditAfterCreate: $id === null,
        );
        $code = Field::text('code', labelKey: 'content-types.fields.code')->help('', 'content-types.editor.code_help');
        if ($id !== null) {
            $code = $code->readonly();
        }

        return AdminEditorBuilder::create('cms.content-types')
            ->form($form)
            ->header(new AdminEditorHeaderDefinition('', titleKey: 'content-types.editor.title', breadcrumbs: [
                ['label' => '', 'labelKey' => 'content-types.module.name', 'href' => $this->urls->route('admin.cms.module.index', ['module' => 'content-types'])],
            ]))
            ->block(new SectionBlock('type', [
                Field::hidden('revision'),
                Field::hidden('definition'),
                new FieldGroupBlock([
                    new FieldColumn(Field::text('name', labelKey: 'content-types.fields.name')->help('', 'content-types.help.type_name')->required()->attributes(['maxlength' => '180']), md: 6, xl: 3),
                    new FieldColumn($code, md: 6, xl: 3),
                    new FieldColumn(Field::text('description', labelKey: 'content-types.fields.description')->help('', 'content-types.help.description')->required()->attributes(['maxlength' => '10000']), md: 9, xl: 5),
                    new FieldColumn(Field::toggle('enabled', labelKey: 'content-types.fields.enabled')->help('', 'content-types.help.enabled'), md: 3, xl: 1),
                ]),
            ]))
            ->block(new CustomViewBlock('content-types::builder', [
                'adminEditorFields' => $this->fields,
                'inspectors' => $this->types->inspectors(),
                'previews' => $this->types->previews(),
            ]))
            ->saveBar(CmsEditorSaveBar::create())
            ->build();
    }
}
