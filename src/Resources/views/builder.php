<?php

declare(strict_types=1);

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Framework\View\ViewHelpers;

/**
 * @var ViewHelpers $helpers
 * @var AdminEditorFieldRenderer $adminEditorFields
 * @var array<string,list<AdminEditorFieldDefinition>> $inspectors
 */
$context = new AdminEditorRenderContext();
$labels = [];
foreach ($helpers->langGroup('content-types') as $key => $label) {
    if (str_starts_with($key, 'builder.')) {
        $labels[substr($key, strlen('builder.'))] = $label;
    }
}
$kinds = [];
foreach (array_keys($inspectors) as $kind) {
    $kinds[$kind] = $helpers->lang('content-types.kinds.' . $kind);
}
$icons = ['image' => 'image', 'gallery' => 'images', 'file' => 'file-earmark', 'files' => 'files', 'text' => 'type', 'textarea' => 'text-left', 'richtext' => 'paragraph', 'integer' => '123', 'decimal' => '123', 'boolean' => 'toggle-on', 'date' => 'calendar3', 'datetime' => 'calendar-week', 'select' => 'card-list'];
?>
<div class="lm-field-builder" data-lemonade-field-builder data-input="definition" data-name-input="name" data-code-input="code" data-translation-prefix="content-types" data-labels="<?= e(json_encode($labels, JSON_THROW_ON_ERROR)) ?>" data-kinds="<?= e(json_encode($kinds, JSON_THROW_ON_ERROR)) ?>" data-icons="<?= e(json_encode($icons, JSON_THROW_ON_ERROR)) ?>">
    <div class="lm-field-builder-columns">
        <section class="card lm-card lm-field-builder-palette" aria-labelledby="builder-palette-title">
            <header class="lm-field-builder-heading">
                <span class="lm-field-builder-heading-icon" aria-hidden="true"><i class="bi bi-plus-square"></i></span>
                <span class="lm-field-builder-heading-copy">
                    <h2 id="builder-palette-title" data-lemonade-i18n="content-types.builder.fields"><?= e($helpers->lang('content-types.builder.fields')) ?></h2>
                    <p data-lemonade-i18n="content-types.builder.palette_help"><?= e($helpers->lang('content-types.builder.palette_help')) ?></p>
                </span>
            </header>
            <div class="lm-form-editor-available-list">
                <?php foreach ($kinds as $kind => $label): ?>
                    <button type="button" class="lm-form-editor-available-button" draggable="true" data-builder-add="<?= e($kind) ?>">
                        <i class="bi bi-<?= e($icons[$kind] ?? 'input-cursor-text') ?>" aria-hidden="true"></i>
                        <span data-lemonade-i18n="content-types.kinds.<?= e($kind) ?>"><?= e($label) ?></span>
                    </button>
                <?php endforeach ?>
            </div>
            <details class="lm-field-builder-archived" data-builder-archived hidden>
                <summary data-lemonade-i18n="content-types.builder.archived"><?= e($helpers->lang('content-types.builder.archived')) ?></summary>
                <div class="lm-form-editor-field-list" data-builder-list></div>
            </details>
        </section>
        <section class="card lm-card lm-field-builder-canvas" aria-labelledby="builder-layout-title">
            <header class="lm-field-builder-heading">
                <span class="lm-field-builder-heading-icon" aria-hidden="true"><i class="bi bi-grid-3x2-gap"></i></span>
                <span class="lm-field-builder-heading-copy">
                    <h2 id="builder-layout-title" data-lemonade-i18n="content-types.builder.layout"><?= e($helpers->lang('content-types.builder.layout')) ?></h2>
                    <p data-lemonade-i18n="content-types.builder.layout_help"><?= e($helpers->lang('content-types.builder.layout_help')) ?></p>
                </span>
            </header>
            <p class="lm-form-help" data-builder-status role="status" aria-live="polite"></p>
            <div class="lm-form-layout-rows" data-builder-layout></div>
        </section>
        <aside class="card lm-card lm-field-builder-settings" aria-labelledby="builder-settings-title">
            <header class="lm-field-builder-heading">
                <span class="lm-field-builder-heading-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                <span class="lm-field-builder-heading-copy">
                    <h2 id="builder-settings-title" data-lemonade-i18n="content-types.builder.inspector"><?= e($helpers->lang('content-types.builder.inspector')) ?></h2>
                    <p data-lemonade-i18n="content-types.builder.inspector_help"><?= e($helpers->lang('content-types.builder.inspector_help')) ?></p>
                </span>
            </header>
            <div data-builder-inspector></div>
        </aside>
    </div>
    <?php foreach ($inspectors as $kind => $fields): ?>
        <template data-builder-inspector-template="<?= e($kind) ?>">
            <?php
            $groups = ['properties' => [], 'validation' => [], 'display' => [], 'advanced' => []];
        foreach ($fields as $field) {
            $group = match ($field->name()) {
                'name', 'required', 'localized', 'help' => 'properties',
                'default_value', 'span' => 'display',
                'code', 'css_class', 'html_id' => 'advanced',
                default => 'validation',
            };
            $groups[$group][] = $field;
        }
        ?>
            <div class="lm-tabs lm-field-builder-inspector-tabs" data-lemonade-tabs>
                <div class="lm-tabs-list" role="tablist" aria-label="<?= e($helpers->lang('content-types.builder.inspector')) ?>" data-lemonade-i18n-aria-label="content-types.builder.inspector">
                    <?php foreach (['properties', 'validation', 'display'] as $group): ?>
                        <?php if ($groups[$group] === []) {
                            continue;
                        } ?>
                        <button type="button" class="lm-tab" role="tab" id="builder-<?= e($kind . '-' . $group) ?>-tab" aria-controls="builder-<?= e($kind . '-' . $group) ?>-panel" aria-selected="<?= $group === 'properties' ? 'true' : 'false' ?>" tabindex="<?= $group === 'properties' ? '0' : '-1' ?>" data-builder-tab="<?= e($group) ?>" data-lemonade-i18n="content-types.builder.<?= e($group) ?>"><?= e($helpers->lang('content-types.builder.' . $group)) ?></button>
                    <?php endforeach ?>
                </div>
                <?php foreach (['properties', 'validation', 'display'] as $group): ?>
                    <?php if ($groups[$group] === []) {
                        continue;
                    } ?>
                    <div class="lm-tab-panel lm-field-builder-inspector-group" role="tabpanel" id="builder-<?= e($kind . '-' . $group) ?>-panel" aria-labelledby="builder-<?= e($kind . '-' . $group) ?>-tab" data-builder-group="<?= e($group) ?>"<?= $group === 'properties' ? '' : ' hidden' ?>>
                        <div class="lm-field-builder-controls">
                            <?php foreach ($groups[$group] as $field): ?>
                                <?= $adminEditorFields->render($field, $context, 'builder-' . $kind) ?>
                            <?php endforeach ?>
                        </div>
                        <?php if ($group === 'display'): ?>
                            <details class="lm-field-builder-advanced">
                                <summary data-lemonade-i18n="content-types.builder.advanced"><?= e($helpers->lang('content-types.builder.advanced')) ?></summary>
                                <div class="lm-field-builder-controls">
                                    <?php foreach ($groups['advanced'] as $field): ?>
                                        <?= $adminEditorFields->render($field, $context, 'builder-' . $kind) ?>
                                    <?php endforeach ?>
                                </div>
                            </details>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
            <div data-builder-field-actions class="lm-field-builder-field-actions"></div>
        </template>
    <?php endforeach ?>
    <template data-builder-option-template>
        <div class="lm-form-editor-option-row lm-field-builder-option" data-lemonade-sortable-item>
            <button type="button" class="btn lm-field-builder-icon-button" data-lemonade-sortable-handle data-option-handle aria-label="<?= e($helpers->lang('content-types.builder.move_help')) ?>" data-lemonade-i18n-aria-label="content-types.builder.move_help"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
            <div class="lm-field-builder-option-control">
                <?= $adminEditorFields->render(Field::text('name', labelKey: 'content-types.fields.name')->required(), $context, 'builder-option') ?>
            </div>
            <div class="lm-field-builder-option-control">
                <?= $adminEditorFields->render(Field::text('code', labelKey: 'content-types.fields.code')->required(), $context, 'builder-option') ?>
            </div>
            <div class="lm-field-builder-option-actions">
                <?php foreach (['up' => ['arrow-up', 'move_up'], 'down' => ['arrow-down', 'move_down'], 'remove' => ['trash', 'remove']] as $action => [$icon, $labelKey]): ?>
                    <button type="button" class="btn lm-field-builder-icon-button" data-option-<?= e($action) ?> title="<?= e($helpers->lang('content-types.builder.' . $labelKey)) ?>" data-lemonade-i18n-title="content-types.builder.<?= e($labelKey) ?>"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span class="visually-hidden" data-lemonade-i18n="content-types.builder.<?= e($labelKey) ?>"><?= e($helpers->lang('content-types.builder.' . $labelKey)) ?></span></button>
                <?php endforeach ?>
            </div>
        </div>
    </template>
</div>
