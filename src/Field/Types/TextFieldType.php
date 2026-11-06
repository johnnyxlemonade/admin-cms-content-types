<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje text pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class TextFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'text';
    }

    public function storage(): string
    {
        return 'text';
    }

    public function ruleControls(): array
    {
        return ['min_length' => Field::number('min_length', labelKey: 'content-types.fields.min_length')->attributes(['min' => '0', 'step' => '1']), 'max_length' => Field::number('max_length', labelKey: 'content-types.fields.max_length')->attributes(['min' => '0', 'step' => '1'])];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::text($name, labelKey: $labelKey);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->textDefinition($input);
    }

    public function editorValues(array $field): array
    {
        return ['default_value' => $field['default_text'] ?? ''];
    }
}
