<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Definuje integer pole pomoci sdilenych controls a validacnich operaci
 */
final readonly class IntegerFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'integer';
    }

    public function storage(): string
    {
        return 'integer';
    }

    public function ruleControls(): array
    {
        return ['min_number' => $this->control('min_number', 'content-types.fields.min_number'), 'max_number' => $this->control('max_number', 'content-types.fields.max_number')];
    }

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition
    {
        return Field::number($name, labelKey: $labelKey)->attributes(['step' => '1']);
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->numberDefinition($input);
    }

    public function editorValues(array $field): array
    {
        return (new DecimalFieldType())->editorValues(['default_decimal' => $field['default_integer'] ?? null] + $field);
    }
}
