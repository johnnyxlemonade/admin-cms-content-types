<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;

/**
 * Popisuje jeden vestaveny typ definice bez persistence a runtime obsahu
 */
interface FieldTypeInterface
{
    public function code(): string;

    public function storage(): string;

    /** @return array<string,AdminEditorInputFieldDefinition|null> */
    public function ruleControls(): array;

    public function control(string $name, ?string $labelKey = null): AdminEditorInputFieldDefinition;

    /**
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function validate(array $input, FieldValidation $validation): array;

    /**
     * @param array<string,mixed> $field
     * @return array<string,int|string|null>
     */
    public function editorValues(array $field): array;
}
