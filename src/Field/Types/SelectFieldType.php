<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

final readonly class SelectFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'select';
    }

    public function storage(): string
    {
        return 'option';
    }

    public function ruleControls(): array
    {
        return ['options' => null];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::select($name, labelKey: $labelKey)->options(['' => ''])->optionLabelKeys(['' => 'content-types.editor.no_default']);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->selectDefinition($input);
    }

    public function editorValues(array $field): array
    {
        return ['default_value' => $field['default_option'] ?? ''];
    }
}
