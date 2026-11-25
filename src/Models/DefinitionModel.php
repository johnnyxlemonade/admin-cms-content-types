<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Framework\Database\Database;

/**
 * Reads and persists Content Type and field definitions
 */
final readonly class DefinitionModel
{
    public function __construct(private Database $database) {}

    /**
     * Returns target type labels for relation field configuration
     *
     * @return array<int,string>
     */
    public function relationTargetOptions(): array
    {
        $query = $this->database->table('cms_content_type')->select(['id', 'name', 'deleted_at'])->orderBy('name');
        $options = [];
        foreach ($query->getArray() as $type) {
            $name = (string) $type['name'];
            $options[(int) $type['id']] = $type['deleted_at'] === null ? $name : $name . ' (deleted)';
        }
        return $options;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function type(int $id, bool $lock = false, bool $includeDeleted = false): ?array
    {
        $query = $this->database->table('cms_content_type')->where('id', $id);
        if (!$includeDeleted) {
            $query = $query->where('deleted_at', null);
        }

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function codeExists(string $code): bool
    {
        return $this->database->table('cms_content_type')->where('code', $code)->exists();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fields(int $id, bool $lock = false): array
    {
        $query = $this->database->table('cms_content_field')->where('type_id', $id)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
        $fields = ($lock ? $query->lockForUpdate() : $query)->getArray();
        $options = [];
        $children = [];
        if ($fields !== []) {
            $fieldIds = array_map(static fn(array $field): int => (int) $field['id'], $fields);
            $optionQuery = $this->database->table('cms_content_field_option')
                ->whereIn('field_id', $fieldIds)
                ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
            foreach (($lock ? $optionQuery->lockForUpdate() : $optionQuery)->getArray() as $option) {
                $options[(int) $option['field_id']][] = $option;
            }
            if ($this->database->tableExists('cms_content_repeater_field')) {
                $childQuery = $this->database->table('cms_content_repeater_field')
                    ->whereIn('field_id', $fieldIds)
                    ->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
                foreach (($lock ? $childQuery->lockForUpdate() : $childQuery)->getArray() as $child) {
                    $children[(int) $child['field_id']][] = $child;
                }
            }
        }
        foreach ($fields as &$field) {
            $field['options'] = $options[(int) $field['id']] ?? [];
            $field['children'] = $children[(int) $field['id']] ?? [];
        }
        unset($field);

        return $fields;
    }

    /**
     * Finds an active relation field by its internal identifier
     *
     * @return array<string,mixed>|null
     */
    public function relationField(int $fieldId): ?array
    {
        return $this->database->table('cms_content_field')->where('id', $fieldId)->where('kind', 'relation')->where('archived_at', null)->first();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function options(int $fieldId, bool $lock = false): array
    {
        $query = $this->database->table('cms_content_field_option')->where('field_id', $fieldId)->orderBy('sort_order', 'ASC');
        return ($lock ? $query->lockForUpdate() : $query)->getArray();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function rows(int $typeId): array
    {
        return $this->database->table('cms_content_layout_row')->where('type_id', $typeId)->orderBy('sort_order', 'ASC')->getArray();
    }

    /**
     * Returns localized canonical path prefixes for one content type
     *
     * @return array<string,string>
     */
    public function routePrefixes(int $typeId): array
    {
        $prefixes = [];
        foreach ($this->database->table('cms_content_type_route_prefix')->where('type_id', $typeId)->getArray() as $row) {
            $prefixes[(string) $row['locale']] = (string) $row['path_prefix'];
        }

        return $prefixes;
    }

    /**
     * @param array<string,int|string|null> $values
     */
    public function createType(array $values): int
    {
        if (!array_key_exists('sort_order', $values)) {
            $values['sort_order'] = $this->nextSortOrder();
        }
        $this->database->table('cms_content_type')->insert($values + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * Returns the next position after all current Content Types
     */
    public function nextSortOrder(): int
    {
        $row = $this->database->table('cms_content_type')
            ->selectRaw('MAX(sort_order) AS max_sort_order')
            ->first();

        return (int) ($row['max_sort_order'] ?? -1) + 1;
    }

    /**
     * @param array<string,int|string|null> $values
     */
    public function updateType(int $id, array $values, int $revision): void
    {
        $this->database->table('cms_content_type')->where('id', $id)->set($values + ['revision' => $revision + 1, 'updated_at' => date('Y-m-d H:i:s')])->update();
    }

    /**
     * Synchronizes only locale prefixes submitted by the definition editor
     *
     * @param array<string,string> $prefixes
     */
    public function saveRoutePrefixes(int $typeId, array $prefixes): void
    {
        foreach ($prefixes as $locale => $pathPrefix) {
            $query = $this->database->table('cms_content_type_route_prefix')
                ->where('type_id', $typeId)
                ->where('locale', $locale);
            if ($pathPrefix === '') {
                $query->delete();

                continue;
            }
            if ($query->exists()) {
                $query->set(['path_prefix' => $pathPrefix])->update();

                continue;
            }
            $this->database->table('cms_content_type_route_prefix')->insert([
                'type_id' => $typeId,
                'locale' => $locale,
                'path_prefix' => $pathPrefix,
            ]);
        }
    }

    public function saveRow(int $typeId, ?int $id, int $position): int
    {
        if ($id !== null) {
            $this->database->table('cms_content_layout_row')->where('id', $id)->where('type_id', $typeId)->set(['sort_order' => $position])->update();

            return $id;
        }
        $this->database->table('cms_content_layout_row')->insert(['type_id' => $typeId, 'sort_order' => $position]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * @param array<string,int|string|null> $values
     */
    public function saveField(int $typeId, ?int $id, array $values): int
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        if ($id !== null) {
            $this->database->table('cms_content_field')->where('id', $id)->where('type_id', $typeId)->set($values)->update();

            return $id;
        }
        $this->database->table('cms_content_field')->insert($values + ['type_id' => $typeId, 'created_at' => $values['updated_at']]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * Missing options are archived to preserve existing value references.
     *
     * @param list<array{code:string,name:string}> $options
     */
    public function saveOptions(int $fieldId, array $options): void
    {
        $existing = array_column($this->options($fieldId), null, 'code');
        $now = date('Y-m-d H:i:s');
        $this->database->table('cms_content_field_option')->where('field_id', $fieldId)->where('archived_at', null)->set(['archived_at' => $now, 'updated_at' => $now])->update();
        foreach ($options as $position => $option) {
            $values = $option + ['sort_order' => $position, 'archived_at' => null, 'updated_at' => $now];
            if (isset($existing[$option['code']])) {
                $this->database->table('cms_content_field_option')->where('id', (int) $existing[$option['code']]['id'])->set($values)->update();
            } else {
                $this->database->table('cms_content_field_option')->insert($values + ['field_id' => $fieldId, 'created_at' => $now]);
            }
        }
    }

    /**
     * Synchronizes child definitions without deleting historical values
     *
     * @param list<array{id:int|null,code:string,name:string,kind:string,required:int,localized:int}> $children
     */
    public function saveRepeaterFields(int $fieldId, array $children): void
    {
        $existing = array_column($this->repeaterFields($fieldId), null, 'id');
        $now = date('Y-m-d H:i:s');
        $this->database->table('cms_content_repeater_field')
            ->where('field_id', $fieldId)
            ->where('archived_at', null)
            ->set(['archived_at' => $now, 'updated_at' => $now])
            ->update();
        foreach ($children as $position => $child) {
            $values = [
                'code' => $child['code'],
                'name' => $child['name'],
                'kind' => $child['kind'],
                'required' => $child['required'],
                'localized' => $child['localized'],
                'sort_order' => $position,
                'archived_at' => null,
                'updated_at' => $now,
            ];
            if ($child['id'] !== null && isset($existing[$child['id']])) {
                $this->database->table('cms_content_repeater_field')
                    ->where('id', $child['id'])
                    ->where('field_id', $fieldId)
                    ->set($values)
                    ->update();

                continue;
            }
            $this->database->table('cms_content_repeater_field')->insert(
                $values + ['field_id' => $fieldId, 'created_at' => $now],
            );
        }
    }

    /**
     * Returns all child definitions including archived ones
     *
     * @return list<array<string,mixed>>
     */
    public function repeaterFields(int $fieldId): array
    {
        return $this->database->table('cms_content_repeater_field')
            ->where('field_id', $fieldId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->getArray();
    }

    /**
     * Reports whether a child definition has any stored values
     */
    public function repeaterFieldHasValues(int $childFieldId): bool
    {
        foreach (['cms_content_repeater_item_value', 'cms_content_repeater_item_translation_value'] as $table) {
            if ($this->database->table($table)->where('child_field_id', $childFieldId)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function fieldHasValues(int $fieldId): bool
    {
        if (!$this->database->tableExists('cms_content_record_value')) {
            return false;
        }
        foreach (['cms_content_record_value', 'cms_content_record_translation_value'] as $table) {
            if ($this->database->table($table)->where('field_id', $fieldId)->exists()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks whether any stored link uses the field
     */
    public function fieldHasRelationValues(int $fieldId): bool
    {
        return $this->database->tableExists('cms_content_record_relation')
            && $this->database->table('cms_content_record_relation')->where('field_id', $fieldId)->exists();
    }

    /**
     * Checks whether reducing the relation to one target would lose links
     */
    public function fieldHasMultipleRelations(int $fieldId): bool
    {
        if (!$this->database->tableExists('cms_content_record_relation')) {
            return false;
        }
        return $this->database->select(
            'SELECT source_record_id FROM cms_content_record_relation WHERE field_id = ? GROUP BY source_record_id HAVING COUNT(*) > 1 LIMIT 1',
            [$fieldId],
        ) !== [];
    }
}
