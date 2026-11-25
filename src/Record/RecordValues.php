<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DomainException;
use InvalidArgumentException;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentValueWriter;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use UnexpectedValueException;

/**
 * Validates and persists scalar and relation values for one Content Type
 */
final class RecordValues implements CmsContentValueWriter
{
    /**
     * @var array<int,int|string|null>
     */
    private array $prepared = [];

    /**
     * @var array<int,array{targetTypeId:int,ids:list<int>}>
     */
    private array $preparedRelations = [];

    /**
     * @var array<int,list<array{id:int|null,values:array<int,string|null>}>>
     */
    private array $preparedRepeaters = [];

    /**
     * @var list<array<string,mixed>>
     */
    private array $fields = [];

    /**
     * @var list<array<string,mixed>>|null
     */
    private ?array $loadedFields = null;

    /**
     * Receives the field definitions, storage and mandatory authorization service
     */
    public function __construct(
        private readonly RecordModule $module,
        private readonly DefinitionModel $definitions,
        private readonly RecordModel $model,
        private readonly FieldTypeRegistry $types,
        private readonly FieldValidation $validation,
        private readonly AuthorizationService $authorization,
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
        // Serialize definition changes with content saves
        $this->definitions->type($this->module->id(), lock: true, includeDeleted: true);
        $this->loadedFields = null;
        $this->fields = $this->scalarFields(lock: true);
        $stored = $this->model->values($this->module->id(), $id, $locale, $this->fields);
        $input = $domain->fields();
        $globalRequired = $state === CmsPublicationState::Published || $this->model->hasOtherPublishedLocale($id, $locale);
        $this->prepared = [];
        $this->preparedRelations = [];
        $this->preparedRepeaters = [];
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

        $relationFields = $this->relationFields(lock: true);
        $currentRelations = $this->model->relationIdsForSources(
            [$id],
            array_map(static fn(array $field): int => (int) $field['id'], $relationFields),
        )[$id] ?? [];
        foreach ($relationFields as $field) {
            $fieldId = (int) $field['id'];
            $key = 'field_' . $fieldId;
            $current = $currentRelations[$fieldId] ?? [];
            $marker = $input[$key . '_present'] ?? null;
            try {
                if ($marker !== null && $marker !== '1') {
                    $this->validation->fail($key, 'invalid');
                }
                if ($marker === null && ($input[$key] ?? null) !== null) {
                    $this->validation->fail($key, 'invalid');
                }
                $selected = $marker === '1'
                    ? $this->relationInput($input[$key] ?? null, (int) $field['relation_multiple'] === 1, $key)
                    : $current;
                if ($selected !== $current) {
                    $this->validateRelationTargets($field, array_values(array_diff($selected, $current)), $id, $key);
                }
                if ($globalRequired && (int) $field['required'] === 1 && $selected === []) {
                    $this->validation->fail($key, 'required');
                }
                if ($selected !== $current) {
                    $this->preparedRelations[$fieldId] = [
                        'targetTypeId' => (int) $field['relation_target_type_id'],
                        'ids' => $selected,
                    ];
                }
            } catch (DefinitionValidationException $exception) {
                $errors[$key] = implode(' ', $exception->errors());
            }
        }

        foreach ($this->repeaterFields(lock: true) as $field) {
            $fieldId = (int) $field['id'];
            $key = 'field_' . $fieldId;
            $marker = $input[$key . '_present'] ?? null;
            try {
                if ($marker === null) {
                    if (array_key_exists($key, $input)) {
                        $this->validation->fail($key, 'invalid');
                    }

                    continue;
                }
                if ($marker !== '1') {
                    $this->validation->fail($key, 'invalid');
                }
                $current = $this->model->repeaterItemsForRecords([$id], $locale, [$field])[$id][$fieldId] ?? [];
                $items = $this->repeaterInput($input[$key] ?? null, $field, $current, $state, $globalRequired, $key);
                $currentIds = array_map(static fn($item): int => $item->getId(), $current);
                $submittedIds = array_values(array_filter(array_column($items, 'id'), is_int(...)));
                $changed = $currentIds !== $submittedIds;
                foreach ($items as $item) {
                    if ($item['id'] === null || $item['values'] !== []) {
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->preparedRepeaters[$fieldId] = $items;
                }
            } catch (DefinitionValidationException $exception) {
                $errors[$key] = implode(' ', $exception->errors());
            }
        }
        if ($errors !== []) {
            $this->prepared = [];
            $this->preparedRelations = [];
            $this->preparedRepeaters = [];
            throw new DefinitionValidationException($errors);
        }

        return $this->prepared !== [] || $this->preparedRelations !== [] || $this->preparedRepeaters !== [];
    }

    /**
     * Parses ordered relation identifiers from submitted form values
     *
     * @return list<int>
     */
    private function relationInput(mixed $input, bool $multiple, string $key): array
    {
        if ($input === null || $input === '') {
            return [];
        }
        if (!$multiple && (is_string($input) || is_int($input))) {
            $input = [$input];
        }
        if (!is_array($input) || !array_is_list($input) || (!$multiple && count($input) > 1)) {
            $this->validation->fail($key, 'invalid');
        }
        $ids = [];
        $seen = [];
        foreach ($input as $value) {
            if (!is_string($value) && !is_int($value)) {
                $this->validation->fail($key, 'invalid');
            }
            $text = (string) $value;
            if (preg_match('/^[1-9][0-9]*$/D', $text) !== 1 || filter_var($text, FILTER_VALIDATE_INT) === false) {
                $this->validation->fail($key, 'invalid');
            }
            $id = (int) $text;
            if (isset($seen[$id])) {
                $this->validation->fail($key, 'duplicate');
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Checks newly selected targets while holding their root row locks
     *
     * @param array<string,mixed> $field
     * @param list<int> $ids
     */
    private function validateRelationTargets(array $field, array $ids, int $sourceId, string $key): void
    {
        if ($ids === []) {
            return;
        }
        $typeId = (int) $field['relation_target_type_id'];
        $targetType = $this->definitions->type($typeId);
        if ($targetType === null || (int) $targetType['enabled'] !== 1) {
            $this->validation->fail($key, 'invalid');
        }
        $targets = $this->model->relationTargets($typeId, $ids, lock: true);
        $module = 'cms.type_' . $typeId;
        foreach ($ids as $id) {
            $target = $targets[$id] ?? null;
            if ($id === $sourceId || $target === null || $target['deleted_at'] !== null) {
                $this->validation->fail($key, 'invalid');
            }
            $owner = $target['owner_id'] === null ? null : (int) $target['owner_id'];
            if (!$this->authorization->canForOwner($module . '.view', $module . '.view_own', $owner)) {
                $this->validation->fail($key, 'invalid');
            }
        }
    }

    /**
     * Checks whether a required scalar value has visible content
     */
    private function isEmptyValue(int|string|null $value): bool
    {
        if ($value === null) {
            return true;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/^\s*$/u', $text) === 1;
    }

    /**
     * Compares stored and submitted values according to the storage type
     */
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
        foreach ($this->preparedRelations as $fieldId => $relation) {
            $this->model->saveRelation($id, $this->module->id(), $fieldId, $relation['targetTypeId'], $relation['ids']);
        }
        $repeaterFields = array_column($this->repeaterFields(), null, 'id');
        foreach ($this->preparedRepeaters as $fieldId => $items) {
            $this->model->saveRepeater($this->module->id(), $id, $locale, $repeaterFields[$fieldId], $items);
        }
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
     * Returns active fields backed by ordered record relations
     *
     * @return list<array<string,mixed>>
     */
    public function relationFields(bool $lock = false): array
    {
        return array_values(array_filter($this->activeFields($lock), static fn(array $field): bool => $field['kind'] === 'relation'));
    }

    /**
     * Returns active ordered repeatable fields
     *
     * @return list<array<string,mixed>>
     */
    public function repeaterFields(bool $lock = false): array
    {
        return array_values(array_filter(
            $this->activeFields($lock),
            static fn(array $field): bool => $field['kind'] === 'repeater',
        ));
    }

    /**
     * Returns repeatable items for the record editor and preserves valid old input
     *
     * @param array<string,mixed> $input
     * @return array<int,list<array{key:string,id:int|null,values:array<int,string>}>>
     */
    public function repeaterEditorItems(int $id, string $locale, array $input = []): array
    {
        $fields = $this->repeaterFields();
        $stored = $this->model->repeaterItemsForRecords([$id], $locale, $fields)[$id] ?? [];
        $result = [];
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $key = 'field_' . $fieldId;
            $submitted = $input[$key] ?? null;
            if (($input[$key . '_present'] ?? null) === '1' && is_array($submitted)) {
                $restored = $this->restoreRepeaterInput($submitted, $field);
                if ($restored !== null) {
                    $result[$fieldId] = $restored;
                    continue;
                }
            }
            foreach ($stored[$fieldId] ?? [] as $item) {
                $values = [];
                foreach ($item->getFields() as $child) {
                    $value = $child->getValue();
                    if ($value !== null && !is_string($value)) {
                        throw new UnexpectedValueException('Invalid repeated text value.');
                    }
                    $values[$child->getId()] = $value ?? '';
                }
                $result[$fieldId][] = [
                    'key' => (string) $item->getId(),
                    'id' => $item->getId(),
                    'values' => $values,
                ];
            }
            $result[$fieldId] ??= [];
        }

        return $result;
    }

    /**
     * Validates one submitted ordered item list
     *
     * @param array<string,mixed> $field
     * @param list<\Lemonade\Cms\ContentTypes\Public\RepeatedFieldItem> $current
     * @return list<array{id:int|null,values:array<int,string|null>}>
     */
    private function repeaterInput(
        mixed $input,
        array $field,
        array $current,
        CmsPublicationState $state,
        bool $globalRequired,
        string $key,
    ): array {
        if (!is_array($input) || !is_array($input['order'] ?? null) || !array_is_list($input['order']) || !is_array($input['items'] ?? null) || count($input['order']) > 100) {
            $this->validation->fail($key, 'invalid');
        }
        if ($globalRequired && (int) $field['required'] === 1 && $input['order'] === []) {
            $this->validation->fail($key, 'required');
        }
        $children = array_column(array_filter(
            $field['children'],
            static fn(array $child): bool => $child['archived_at'] === null,
        ), null, 'id');
        $currentById = [];
        foreach ($current as $currentItem) {
            $currentById[$currentItem->getId()] = $currentItem;
        }
        $seen = [];
        $prepared = [];
        foreach ($input['order'] as $token) {
            if (!is_string($token) || preg_match('/^(?:[1-9][0-9]*|new_[a-zA-Z0-9-]{1,64})$/D', $token) !== 1 || isset($seen[$token])) {
                $this->validation->fail($key, 'invalid');
            }
            $seen[$token] = true;
            $itemInput = $input['items'][$token] ?? null;
            if (!is_array($itemInput)) {
                $this->validation->fail($key, 'invalid');
            }
            $itemId = str_starts_with($token, 'new_') ? null : (int) $token;
            if ($itemId !== null && (!isset($currentById[$itemId]) || (string) ($itemInput['id'] ?? '') !== $token)) {
                $this->validation->fail($key, 'invalid');
            }
            $allowedKeys = ['id'];
            $changedValues = [];
            foreach ($children as $childId => $child) {
                $childKey = 'field_' . $childId;
                $allowedKeys[] = $childKey;
                $value = $this->validation->text($itemInput, $childKey);
                try {
                    $type = $this->types->get((string) $child['kind']);
                    $normalized = $type->validate(['default_value' => $value], $this->validation)['values']['default_text'];
                    if ($normalized !== null && !is_string($normalized)) {
                        $this->validation->fail($key, 'invalid');
                    }
                    $required = (int) $child['localized'] === 1 ? $state === CmsPublicationState::Published : $globalRequired;
                    if ($required && (int) $child['required'] === 1 && $this->isEmptyValue($normalized)) {
                        $this->validation->fail($key, 'required');
                    }
                } catch (DefinitionValidationException) {
                    $this->validation->fail($key, 'invalid');
                }
                $currentValue = $itemId === null ? null : $currentById[$itemId]->getField((string) $child['code'])->getValue();
                if ($currentValue !== null && !is_string($currentValue)) {
                    throw new UnexpectedValueException('Invalid repeated text value.');
                }
                if ($itemId === null || ($currentValue ?? '') !== ($normalized ?? '')) {
                    $changedValues[(int) $childId] = $normalized;
                }
            }
            if (array_diff(array_keys($itemInput), $allowedKeys) !== []) {
                $this->validation->fail($key, 'invalid');
            }
            $prepared[] = ['id' => $itemId, 'values' => $changedValues];
        }
        if (count($seen) !== count($input['items'])) {
            $this->validation->fail($key, 'invalid');
        }

        return $prepared;
    }

    /**
     * Restores submitted items for a validation response without trusting their shape
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $field
     * @return list<array{key:string,id:int|null,values:array<int,string>}>|null
     */
    private function restoreRepeaterInput(array $input, array $field): ?array
    {
        if (!is_array($input['order'] ?? null) || !array_is_list($input['order']) || !is_array($input['items'] ?? null)) {
            return null;
        }
        $children = array_column($field['children'], null, 'id');
        $items = [];
        foreach ($input['order'] as $token) {
            if (!is_string($token) || !is_array($input['items'][$token] ?? null)) {
                return null;
            }
            $values = [];
            foreach ($children as $childId => $child) {
                if ($child['archived_at'] === null) {
                    $values[(int) $childId] = $this->validation->text($input['items'][$token], 'field_' . $childId);
                }
            }
            $items[] = [
                'key' => $token,
                'id' => ctype_digit($token) ? (int) $token : null,
                'values' => $values,
            ];
        }

        return $items;
    }

    /**
     * Returns authorized labels for existing relation selections
     *
     * @return array<int,array<int,string>>
     */
    public function relationOptions(int $id, string $locale): array
    {
        return $this->relationOptionsForRecords([$id], $locale)[$id] ?? [];
    }

    /**
     * Restores authorized selections from submitted editor values in their submitted order
     *
     * @param array<string,mixed> $input
     * @return array<int,array<int,string>>
     */
    public function relationOptionsWithInput(int $id, string $locale, array $input): array
    {
        $stored = $this->relationOptions($id, $locale);
        $selected = [];
        $idsByType = [];
        foreach ($this->relationFields() as $field) {
            $fieldId = (int) $field['id'];
            $key = 'field_' . $fieldId;
            if (($input[$key . '_present'] ?? null) !== '1') {
                continue;
            }
            try {
                $ids = $this->relationInput($input[$key] ?? null, (int) $field['relation_multiple'] === 1, $key);
            } catch (DefinitionValidationException) {
                continue;
            }
            $selected[$fieldId] = $ids;
            $typeId = (int) $field['relation_target_type_id'];
            foreach ($ids as $targetId) {
                $idsByType[$typeId][$targetId] = $targetId;
            }
        }
        if ($selected === []) {
            return $stored;
        }

        $labels = [];
        foreach ($idsByType as $typeId => $targetIds) {
            $targetType = $this->definitions->type($typeId);
            if ($targetType === null || (int) $targetType['enabled'] !== 1) {
                continue;
            }
            foreach ($this->model->relationTargetLabels($typeId, array_values($targetIds), $locale) as $targetId => $target) {
                if ((int) $targetId === $id) {
                    continue;
                }
                $ownerId = $target['owner_id'] === null ? null : (int) $target['owner_id'];
                $module = 'cms.type_' . $typeId;
                if (!$this->authorization->canForOwner($module . '.view', $module . '.view_own', $ownerId)) {
                    continue;
                }
                $title = trim((string) ($target['title'] ?? ''));
                $labels[$typeId][(int) $targetId] = $title === '' ? '#' . $targetId : $title;
            }
        }

        foreach ($this->relationFields() as $field) {
            $fieldId = (int) $field['id'];
            if (!isset($selected[$fieldId])) {
                continue;
            }
            $typeId = (int) $field['relation_target_type_id'];
            $options = [];
            foreach ($selected[$fieldId] as $targetId) {
                if (isset($labels[$typeId][$targetId])) {
                    $options[$targetId] = $labels[$typeId][$targetId];
                } elseif (isset($stored[$fieldId][$targetId])) {
                    $options[$targetId] = $stored[$fieldId][$targetId];
                }
            }
            $stored[$fieldId] = $options + ($stored[$fieldId] ?? []);
        }
        return $stored;
    }

    /**
     * Returns authorized labels for relation selections on several source records
     *
     * @param list<int> $sourceIds
     * @return array<int,array<int,array<int,string>>>
     */
    public function relationOptionsForRecords(array $sourceIds, string $locale): array
    {
        $modules = [];
        foreach ($this->relationFields() as $field) {
            $modules[(int) $field['id']] = 'cms.type_' . (int) $field['relation_target_type_id'];
        }
        if ($sourceIds === [] || $modules === []) {
            return [];
        }

        $options = [];
        foreach ($this->model->relationRowsForSources($sourceIds, array_keys($modules), $locale) as $target) {
            $sourceId = (int) $target['source_record_id'];
            $fieldId = (int) $target['field_id'];
            $targetId = (int) $target['id'];
            $owner = $target['owner_id'] === null ? null : (int) $target['owner_id'];
            $module = $modules[$fieldId];
            if (!$this->authorization->canForOwner($module . '.view', $module . '.view_own', $owner)) {
                $options[$sourceId][$fieldId][$targetId] = '#' . $targetId . ' [unavailable]';
                continue;
            }
            $title = trim((string) ($target['title'] ?? ''));
            $label = $title === '' ? '#' . $targetId : $title;
            if ($target['deleted_at'] !== null) {
                $label .= ' [deleted]';
            }
            $options[$sourceId][$fieldId][$targetId] = $label;
        }
        return $options;
    }

    /**
     * Returns current scalar and ordered relation values for the editor
     *
     * @return array<string,int|string|list<string>|null>
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
        $relationFields = $this->relationFields();
        $relationIds = $this->model->relationIdsForSources(
            [$id],
            array_map(static fn(array $field): int => (int) $field['id'], $relationFields),
        )[$id] ?? [];
        foreach ($relationFields as $field) {
            $fieldId = (int) $field['id'];
            $ids = $relationIds[$fieldId] ?? [];
            $values['field_' . $fieldId] = (int) $field['relation_multiple'] === 1
                ? array_map(strval(...), $ids)
                : (isset($ids[0]) ? (string) $ids[0] : '');
            $values['field_' . $fieldId . '_present'] = '1';
        }
        return $values;
    }

    /**
     * Converts a stored scalar into its editor representation
     *
     * @param array<string,mixed> $field
     */
    private function displayValue(array $field, int|string|null $value): int|string|null
    {
        $type = $this->types->get((string) $field['kind']);
        return $type->editorValues(['default_' . $type->storage() => $value] + $field)['default_value'];
    }
}
