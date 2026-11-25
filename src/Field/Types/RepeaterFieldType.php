<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Defines an ordered group whose child values use dedicated relational storage
 */
final readonly class RepeaterFieldType implements FieldTypeInterface
{
    /**
     * Returns the field type code
     */
    public function code(): string
    {
        return 'repeater';
    }

    /**
     * Reports that the parent field has no scalar value column
     */
    public function storage(): ?string
    {
        return null;
    }

    /**
     * Returns no scalar validation controls
     *
     * @return array<string,AdminEditorInputFieldDefinition|null>
     */
    public function ruleControls(): array
    {
        return [];
    }

    /**
     * Returns no scalar editor control for the group
     */
    public function control(string $name, ?string $labelKey = null): ?AdminEditorInputFieldDefinition
    {
        return null;
    }

    /**
     * Validates parent settings while child definitions are validated separately
     */
    public function validate(array $input, FieldValidation $validation): array
    {
        if (!in_array($input['localized'] ?? 0, [0, '0', false, ''], true)) {
            $validation->fail('localized', 'irrelevant');
        }
        if (isset($input['default_value']) && $input['default_value'] !== '') {
            $validation->fail('default_value', 'irrelevant');
        }

        return ['values' => [], 'options' => []];
    }

    /**
     * Returns no scalar editor values
     *
     * @return array<string,int|string|null>
     */
    public function editorValues(array $field): array
    {
        return [];
    }
}
