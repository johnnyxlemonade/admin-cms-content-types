<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje decimal pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class DecimalFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'decimal';
    }

    public function storage(): string
    {
        return 'decimal';
    }

    public function ruleControls(): array
    {
        return ['min_number' => $this->control('min_number', 'content-types.fields.min_number'), 'max_number' => $this->control('max_number', 'content-types.fields.max_number'), 'decimal_places' => Field::number('decimal_places', labelKey: 'content-types.fields.decimal_places')->attributes(['min' => '0', 'max' => '8', 'step' => '1'])];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::number($name, labelKey: $labelKey)->attributes(['step' => 'any']);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->numberDefinition($input, decimal: true);
    }

    public function editorValues(array $field): array
    {
        $values = ['default_value' => $field['default_decimal'] ?? '', 'min_number' => $field['min_number'] ?? null, 'max_number' => $field['max_number'] ?? null];
        foreach ($values as &$value) {
            if (is_string($value) && str_contains($value, '.')) {
                $value = rtrim(rtrim($value, '0'), '.');
            }
        }
        unset($value);
        return $values;
    }
}
