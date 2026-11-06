<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje boolean pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class BooleanFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'boolean';
    }

    public function storage(): string
    {
        return 'boolean';
    }

    public function ruleControls(): array
    {
        return [];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::select($name, labelKey: $labelKey)->options(['' => '', '0' => '', '1' => ''])->optionLabelKeys(['' => 'content-types.editor.no_default', '0' => 'content-types.editor.no', '1' => 'content-types.editor.yes']);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return ['values' => ['default_boolean' => $validation->text($input, 'default_value') === '' ? null : $validation->boolean($input, 'default_value')], 'options' => []];
    }

    public function editorValues(array $field): array
    {
        return ['default_value' => $field['default_boolean'] ?? ''];
    }
}
