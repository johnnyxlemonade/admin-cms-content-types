<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje datetime pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class DateTimeFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'datetime';
    }

    public function storage(): string
    {
        return 'datetime';
    }

    public function ruleControls(): array
    {
        return ['min_date' => $this->control('min_date', 'content-types.fields.min_date'), 'max_date' => $this->control('max_date', 'content-types.fields.max_date')];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::datetimeLocal($name, labelKey: $labelKey);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->dateDefinition($input, withTime: true);
    }

    public function editorValues(array $field): array
    {
        return array_map(static fn(mixed $value): string => str_replace(' ', 'T', substr((string) $value, 0, 16)), ['default_value' => $field['default_datetime'] ?? '', 'min_date' => $field['min_date'] ?? '', 'max_date' => $field['max_date'] ?? '']);
    }
}
