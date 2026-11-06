<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje date pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class DateFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'date';
    }

    public function storage(): string
    {
        return 'date';
    }

    public function ruleControls(): array
    {
        return ['min_date' => $this->control('min_date', 'content-types.fields.min_date'), 'max_date' => $this->control('max_date', 'content-types.fields.max_date')];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::date($name, labelKey: $labelKey);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->dateDefinition($input);
    }

    public function editorValues(array $field): array
    {
        return array_map(static fn(mixed $value): string => substr((string) $value, 0, 10), ['default_value' => $field['default_date'] ?? '', 'min_date' => $field['min_date'] ?? '', 'max_date' => $field['max_date'] ?? '']);
    }
}
