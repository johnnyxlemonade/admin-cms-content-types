<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

/**
 * Defines nonlocalized links to records of a configured Content Type
 */
final readonly class RelationFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'relation';
    }

    public function storage(): ?string
    {
        return null;
    }

    public function ruleControls(): array
    {
        return ['relation_target_type_id' => null, 'relation_multiple' => null];
    }

    public function control(string $name, ?string $labelKey = null): ?AdminEditorInputFieldDefinition
    {
        return null;
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        if (!in_array($input['localized'] ?? 0, [0, '0', false, ''], true)) {
            $validation->fail('localized', 'irrelevant');
        }
        if (isset($input['default_value']) && $input['default_value'] !== '') {
            $validation->fail('default_value', 'irrelevant');
        }
        $target = $validation->text($input, 'relation_target_type_id');
        if (preg_match('/^[1-9][0-9]*$/D', $target) !== 1 || filter_var($target, FILTER_VALIDATE_INT) === false) {
            $validation->fail('relation_target_type_id', 'invalid');
        }
        return [
            'values' => [
                'relation_target_type_id' => (int) $target,
                'relation_multiple' => $validation->boolean($input, 'relation_multiple'),
            ],
            'options' => [],
        ];
    }

    public function editorValues(array $field): array
    {
        return [];
    }
}
