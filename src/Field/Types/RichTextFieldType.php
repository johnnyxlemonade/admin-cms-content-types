<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

final readonly class RichTextFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'richtext';
    }

    public function storage(): string
    {
        return 'text';
    }

    public function ruleControls(): array
    {
        return (new TextFieldType())->ruleControls();
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::textarea($name, labelKey: $labelKey)->richText();
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->textDefinition($input, formatted: true);
    }

    public function editorValues(array $field): array
    {
        return ['default_value' => $field['default_text'] ?? ''];
    }
}
