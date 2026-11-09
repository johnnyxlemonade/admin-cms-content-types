<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field\Types;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Cms\ContentTypes\Field\FieldTypeInterface;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;

final readonly class ImageFieldType implements FieldTypeInterface
{
    public function code(): string
    {
        return 'image';
    }

    public function storage(): ?string
    {
        return null;
    }

    public function ruleControls(): array
    {
        return [];
    }

    public function control(string $name, ?string $labelKey = null): ?AdminEditorInputFieldDefinition
    {
        return null;
    }

    public function validate(array $input, FieldValidation $validation): array
    {
        return $validation->mediaDefinition($input);
    }

    public function editorValues(array $field): array
    {
        return [];
    }
}
