<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DomainException;
use InvalidArgumentException;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentValueWriter;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;

final class RecordValues implements CmsContentValueWriter
{
    /** @var array<int,int|string|null> */
    private array $prepared = [];
    /** @var list<array<string,mixed>> */
    private array $fields = [];
    /** @var list<array<string,mixed>>|null */
    private ?array $loadedFields = null;

    public function __construct(
        private readonly RecordModule $module,
        private readonly DefinitionModel $definitions,
        private readonly RecordModel $model,
        private readonly FieldTypeRegistry $types,
        private readonly FieldValidation $validation,
    ) {}

    public function beforeCreateDraft(string $locale): void
    {
        $type = $this->definitions->type($this->module->id(), lock: true);
        if ($type === null || (int) $type['enabled'] !== 1) {
            throw new DomainException('records.validation.type_disabled');
        }
    }

    public function createDraft(int $id, string $locale): void
    {
        $this->prepare($id, $locale, CmsPublicationState::Draft, new RecordData(null, []));
        $this->save($id, $locale);
    }

    public function prepare(int $id, string $locale, CmsPublicationState $state, object $domain): bool
    {
        if (!$domain instanceof RecordData) {
            throw new InvalidArgumentException('Unexpected content record data.');
        }
        // Serialize definition changes with content saves.
        $this->definitions->type($this->module->id(), lock: true, includeDeleted: true);
        $this->loadedFields = null;
        $this->fields = $this->scalarFields(lock: true);
        $stored = $this->model->values($this->module->id(), $id, $locale, $this->fields);
        $input = $domain->fields();
        $globalRequired = $state === CmsPublicationState::Published || $this->model->hasOtherPublishedLocale($id, $locale);
        $this->prepared = [];
        $errors = [];
        foreach ($this->fields as $field) {
            $fieldId = (int) $field['id'];
            $key = 'field_' . $fieldId;
            $type = $this->types->get((string) $field['kind']);
            $projection = $this->types->editorField($field);
            if (array_key_exists($key, $input)) {
                $value = $input[$key];
            } elseif (array_key_exists($fieldId, $stored)) {
                $value = $this->displayValue($field, $stored[$fieldId]);
            } else {
                $value = $projection['default_value'];
            }
            try {
                $result = $type->validate(['default_value' => $value] + $projection, $this->validation);
                $normalized = $result['values']['default_' . $type->storage()];
                $required = (int) $field['localized'] === 1 ? $state === CmsPublicationState::Published : $globalRequired;
                if ($required && (int) $field['required'] === 1 && $this->isEmptyValue($normalized)) {
                    $this->validation->fail($key, 'required');
                }
            } catch (DefinitionValidationException $exception) {
                $errors[$key] = implode(' ', $exception->errors());
                continue;
            }
            $storedValue = $stored[$fieldId] ?? null;
            if (!$this->valuesEqual($type->storage(), $storedValue, $normalized) || !array_key_exists($fieldId, $stored)) {
                $this->prepared[$fieldId] = $normalized;
            }
        }
        if ($errors !== []) {
            $this->prepared = [];
            throw new DefinitionValidationException($errors);
        }

        return $this->prepared !== [];
    }

    private function isEmptyValue(int|string|null $value): bool
    {
        if ($value === null) {
            return true;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/^\s*$/u', $text) === 1;
    }

    private function valuesEqual(?string $storage, int|string|null $stored, int|string|null $value): bool
    {
        if ($storage === 'decimal' && $stored !== null && $value !== null) {
            return FieldValidation::compareNumbers((string) $stored, (string) $value) === 0;
        }
        if ($stored === null) {
            return $value === null;
        }

        return (string) $stored === (string) $value;
    }

    public function save(int $id, string $locale): void
    {
        $this->model->save($this->module->id(), $id, $locale, $this->fields, $this->prepared);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeFields(bool $lock = false): array
    {
        return $this->loadedFields ??= array_values(array_filter(
            $this->definitions->fields($this->module->id(), $lock),
            static fn(array $field): bool => $field['archived_at'] === null,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function scalarFields(bool $lock = false): array
    {
        return array_values(array_filter(
            $this->activeFields($lock),
            fn(array $field): bool => $this->types->get((string) $field['kind'])->storage() !== null,
        ));
    }

    /**
     * @return array<string,int|string|null>
     */
    public function editorValues(int $id, string $locale): array
    {
        $fields = $this->scalarFields();
        $stored = $this->model->values($this->module->id(), $id, $locale, $fields);
        $values = [];
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $values['field_' . $fieldId] = array_key_exists($fieldId, $stored)
                ? $this->displayValue($field, $stored[$fieldId])
                : $this->types->get((string) $field['kind'])->editorValues($field)['default_value'];
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $field
     */
    private function displayValue(array $field, int|string|null $value): int|string|null
    {
        $type = $this->types->get((string) $field['kind']);
        return $type->editorValues(['default_' . $type->storage() => $value] + $field)['default_value'];
    }
}
