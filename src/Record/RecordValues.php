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

/**
 * Validuje definovana pole a zapisuje je uvnitr existujici CMS transakce
 */
final class RecordValues implements CmsContentValueWriter
{
    /** @var array<int,int|string|null> */
    private array $prepared = [];
    /** @var list<array<string,mixed>> */
    private array $fields = [];
    /** @var list<array<string,mixed>>|null */
    private ?array $loadedFields = null;

    /**
     * Nastavuje uloziste a sdilene validatory typu poli
     */
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
        // Zamek definice serializuje zmenu pravidel s ulozenim obsahu.
        $this->definitions->type($this->module->id(), lock: true, includeDeleted: true);
        $this->loadedFields = null;
        $this->fields = $this->activeFields(lock: true);
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
            $value = array_key_exists($key, $input) ? $input[$key] : (array_key_exists($fieldId, $stored) ? $this->displayValue($field, $stored[$fieldId]) : $projection['default_value']);
            try {
                $result = $type->validate(['default_value' => $value] + $projection, $this->validation);
                $normalized = $result['values']['default_' . $type->storage()];
                $required = (int) $field['localized'] === 1 ? $state === CmsPublicationState::Published : $globalRequired;
                if ($required && (int) $field['required'] === 1 && ($normalized === null || preg_match('/^\s*$/u', html_entity_decode(strip_tags((string) $normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1)) {
                    $this->validation->fail($key, 'required');
                }
            } catch (DefinitionValidationException $exception) {
                $errors[$key] = implode(' ', $exception->errors());
                continue;
            }
            $old = $stored[$fieldId] ?? null;
            $equal = $type->storage() === 'decimal' && $old !== null && $normalized !== null
                ? FieldValidation::compareNumbers((string) $old, (string) $normalized) === 0
                : ($old === null ? $normalized === null : (string) $old === (string) $normalized);
            if (!$equal || !array_key_exists($fieldId, $stored)) {
                $this->prepared[$fieldId] = $normalized;
            }
        }
        if ($errors !== []) {
            $this->prepared = [];
            throw new DefinitionValidationException($errors);
        }

        return $this->prepared !== [];
    }

    public function save(int $id, string $locale): void
    {
        $this->model->save($this->module->id(), $id, $locale, $this->fields, $this->prepared);
    }

    /**
     * Nacte pouze nearchivovane definice v poradi Builderu
     *
     * @return list<array<string,mixed>>
     */
    public function activeFields(bool $lock = false): array
    {
        return $this->loadedFields ??= array_values(array_filter($this->definitions->fields($this->module->id(), $lock), static fn(array $field): bool => $field['archived_at'] === null));
    }

    /**
     * Vrati editorove hodnoty, vcetne defaultu nove pridanych poli
     *
     * @return array<string,int|string|null>
     */
    public function editorValues(int $id, string $locale): array
    {
        $fields = $this->activeFields();
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
     * Pouzije existujici normalizaci databazovych hodnot pro HTML controls
     *
     * @param array<string,mixed> $field
     */
    private function displayValue(array $field, int|string|null $value): int|string|null
    {
        $type = $this->types->get((string) $field['kind']);
        return $type->editorValues(['default_' . $type->storage() => $value] + $field)['default_value'];
    }
}
