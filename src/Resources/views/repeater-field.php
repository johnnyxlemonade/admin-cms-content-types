<?php

declare(strict_types=1);

use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Framework\View\ViewHelpers;

/**
 * @var ViewHelpers $helpers
 * @var AdminEditorFieldRenderer $adminEditorFields
 * @var array<string,mixed> $definition
 * @var list<array{key:string,id:int|null,values:array<int,string>}> $items
 * @var list<string> $errors
 * @var string $moduleCode
 * @var int $recordId
 * @var int $publicationState
 * @var bool $canPublish
 * @var bool $otherPublishedLocale
 */
$fieldName = 'field_' . $definition['id'];
$children = array_values(array_filter(
    $definition['children'],
    static fn(array $child): bool => $child['archived_at'] === null,
));
$renderItem = static function (string $key, ?int $id, array $values) use (
    $adminEditorFields,
    $children,
    $definition,
    $fieldName,
    $helpers,
    $moduleCode,
    $otherPublishedLocale,
    $publicationState,
    $canPublish,
    $recordId,
): string {
    ob_start();
    ?>
    <article class="card mb-3" data-lemonade-sortable-item="<?= e($key) ?>" data-lemonade-repeatable-item>
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <button type="button" class="btn btn-light btn-sm" data-lemonade-sortable-handle aria-label="<?= e($helpers->lang('records.repeater.move')) ?>" data-lemonade-i18n-aria-label="records.repeater.move"><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
            <input type="hidden" name="<?= e($fieldName) ?>[order][]" value="<?= e($key) ?>">
            <?php if ($id !== null): ?><input type="hidden" name="<?= e($fieldName) ?>[items][<?= e($key) ?>][id]" value="<?= e((string) $id) ?>"><?php endif; ?>
            <button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-lemonade-repeatable-remove><i class="bi bi-trash me-1" aria-hidden="true"></i><span data-lemonade-i18n="records.repeater.remove"><?= e($helpers->lang('records.repeater.remove')) ?></span></button>
        </div>
        <div class="card-body">
            <?php foreach ($children as $child): ?>
                <?php
                $name = $fieldName . '[items][' . $key . '][field_' . $child['id'] . ']';
                $control = match ($child['kind']) {
                    'text' => Field::text($name, (string) $child['name']),
                    'textarea' => Field::textarea($name, (string) $child['name']),
                    'richtext' => Field::textarea($name, (string) $child['name'])->richText(),
                    default => throw new \InvalidArgumentException('Unsupported repeated field type.'),
                };
                $required = (int) $child['required'] === 1;
                $control = $control->required($required);
                if ($required) {
                    $control = $control->attributes(CmsPublicationSection::requiredAttributes(
                        state: $publicationState,
                        canPublish: $canPublish,
                        globallyRequired: (int) $child['localized'] !== 1 && $otherPublishedLocale,
                        messageKey: 'content-types.validation.required',
                    ));
                }
                $context = new AdminEditorRenderContext(
                    values: [$name => $values[(int) $child['id']] ?? ''],
                    moduleCode: $moduleCode,
                    entityId: $recordId,
                );
                ?>
                <?= $adminEditorFields->render($control, $context, 'repeater-' . $definition['id'] . '-' . $key) ?>
            <?php endforeach; ?>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="lm-form-group mb-4" data-lemonade-repeatable-group>
    <h3 class="lm-form-label<?= (int) $definition['required'] === 1 ? ' required' : '' ?>"><?= e((string) $definition['name']) ?></h3>
    <?php if (($definition['help'] ?? '') !== ''): ?><p class="lm-form-help"><?= e((string) $definition['help']) ?></p><?php endif; ?>
    <input type="hidden" name="<?= e($fieldName) ?>_present" value="1">
    <div data-lemonade-repeatable-list data-lemonade-sortable>
        <?php foreach ($items as $item): ?><?= $renderItem($item['key'], $item['id'], $item['values']) ?><?php endforeach; ?>
    </div>
    <p class="lm-form-help" data-lemonade-repeatable-empty<?= $items === [] ? '' : ' hidden' ?> data-lemonade-i18n="records.repeater.empty"><?= e($helpers->lang('records.repeater.empty')) ?></p>
    <?php if ($errors !== []): ?><p class="lm-form-help is-error"><?= e($errors[0]) ?></p><?php endif; ?>
    <button type="button" class="btn btn-outline-primary btn-sm" data-lemonade-repeatable-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i><span data-lemonade-i18n="records.repeater.add"><?= e($helpers->lang('records.repeater.add')) ?></span></button>
    <template data-lemonade-repeatable-template><?= $renderItem('__item__', null, []) ?></template>
</div>
