<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Queries and persists dynamic records and their typed values
 */
final readonly class RecordModel
{
    public const array FILTERS = [
        'integer' => ['number', '/^-?(?:0|[1-9][0-9]{0,15})$/D'],
        'decimal' => ['number', '/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D'],
        'date' => ['date', '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D'],
        'datetime' => ['datetime-local', '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D'],
    ];

    /**
     * Receives the database and field type registry used by record queries
     */
    public function __construct(private Database $database, private FieldTypeRegistry $types) {}

    /**
     * Returns enabled and disabled types so existing content stays manageable
     *
     * @return list<RecordModule>
     */
    public function modules(): array
    {
        if (!$this->database->tableExists('cms_content_record')) {
            return [];
        }
        $routePrefixes = [];
        if ($this->database->tableExists('cms_content_type_route_prefix')) {
            foreach ($this->database->table('cms_content_type_route_prefix')->getArray() as $row) {
                $routePrefixes[(int) $row['type_id']][(string) $row['locale']] = (string) $row['path_prefix'];
            }
        }

        return array_map(static fn(array $row): RecordModule => new RecordModule(
            id: (int) $row['id'],
            name: (string) $row['name'],
            typeCode: (string) $row['code'],
            enabled: (int) $row['enabled'] === 1 && $row['deleted_at'] === null,
            description: $row['description'] === null ? null : (string) $row['description'],
            galleryEnabled: (int) ($row['gallery_enabled'] ?? 0) === 1,
            attachmentsEnabled: (int) ($row['attachments_enabled'] ?? 0) === 1,
            thumbnailEnabled: (int) ($row['thumbnail_enabled'] ?? 0) === 1,
            tagsEnabled: (int) ($row['tags_enabled'] ?? 0) === 1,
            routePrefixes: $routePrefixes[(int) $row['id']] ?? [],
        ), $this->database->table('cms_content_type')->orderBy('id')->getArray());
    }

    public function translationId(int $id, string $locale): ?int
    {
        $row = $this->database->table('cms_content_record_translation')->select(['id'])->where('record_id', $id)->where('locale', $locale)->first();
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Distinguishes a missing row from an explicitly empty value when applying defaults
     *
     * @param list<array<string,mixed>> $fields
     * @return array<int,int|string|null>
     */
    public function values(int $typeId, int $id, string $locale, array $fields): array
    {
        $rows = $this->database->table('cms_content_record_value')->where('type_id', $typeId)->where('record_id', $id)->getArray();
        $translationId = $this->translationId($id, $locale);
        if ($translationId !== null) {
            $rows = [...$rows, ...$this->database->table('cms_content_record_translation_value')->where('type_id', $typeId)->where('translation_id', $translationId)->getArray()];
        }
        $definitions = array_column($fields, null, 'id');
        $values = [];
        foreach ($rows as $row) {
            $field = $definitions[$row['field_id']] ?? null;
            if ($field === null) {
                continue;
            }
            $storage = $this->types->get((string) $field['kind'])->storage();
            $value = $row['value_' . $storage];
            if ($storage === 'option' && $value !== null) {
                $options = array_column($field['options'], 'code', 'id');
                $value = $options[$value] ?? null;
            }
            $values[(int) $row['field_id']] = $value === null ? null : (string) $value;
        }
        return $values;
    }

    /**
     * Nacte vlastni pole cele stranky zaznamu dvema hodnotovymi dotazy
     *
     * @param list<int> $ids
     * @param list<array<string,mixed>> $fields
     * @return array<int,list<\Lemonade\Cms\ContentTypes\Record\RecordFieldValue>>
     */
    public function fieldValues(int $typeId, array $ids, string $locale, array $fields): array
    {
        if ($ids === []) {
            return [];
        }
        $stored = [];
        $rows = $this->database->table('cms_content_record_value')->where('type_id', $typeId)->whereIn('record_id', $ids)->getArray();
        $localized = $this->database->table('cms_content_record_translation_value v')
            ->selectRaw('v.*')->joinOn('cms_content_record_translation t', 't.id', '=', 'v.translation_id')
            ->where('v.type_id', $typeId)->whereIn('v.record_id', $ids)->where('t.locale', $locale)->getArray();
        foreach ([0 => $rows, 1 => $localized] as $scope => $values) {
            foreach ($values as $row) {
                $stored[$scope][(int) $row['record_id']][(int) $row['field_id']] = $row;
            }
        }
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [];
            foreach ($fields as $field) {
                if ($field['archived_at'] !== null || $field['kind'] === 'relation') {
                    continue;
                }
                $storage = $this->types->get((string) $field['kind'])->storage();
                $row = $stored[(int) $field['localized']][$id][(int) $field['id']] ?? [];
                $value = $storage === null ? null : ($row['value_' . $storage] ?? null);
                $result[$id][] = \Lemonade\Cms\ContentTypes\Record\RecordFieldValue::fromStored(
                    $field,
                    $value === null ? null : (string) $value,
                );
            }
        }
        return $result;
    }

    /**
     * Persists scalar values while the caller holds the CMS root lock
     *
     * @param list<array<string,mixed>> $fields
     * @param array<int,int|string|null> $values
     */
    public function save(int $typeId, int $id, string $locale, array $fields, array $values): void
    {
        $translationId = $this->translationId($id, $locale);
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            if (!array_key_exists($fieldId, $values)) {
                continue;
            }
            $localized = (int) $field['localized'] === 1;
            $table = $localized ? 'cms_content_record_translation_value' : 'cms_content_record_value';
            $identity = ['record_id' => $id, 'type_id' => $typeId, 'field_id' => $fieldId];
            if ($localized) {
                $identity['translation_id'] = $translationId;
            }
            $storage = $this->types->get((string) $field['kind'])->storage();
            $value = $values[$fieldId];
            if ($storage === 'option' && $value !== null) {
                $options = array_column($field['options'], 'id', 'code');
                $value = (int) $options[$value];
            }
            $query = $this->database->table($table)->where('record_id', $id)->where('field_id', $fieldId);
            if ($localized) {
                $query = $query->where('translation_id', $translationId);
            }
            $columns = ['value_' . $storage => $value];
            if ($query->exists()) {
                $query->set($columns)->update();
            } else {
                $this->database->table($table)->insert($identity + $columns);
            }
        }
    }

    /**
     * Checks whether shared required fields must remain valid for another published translation
     */
    public function hasOtherPublishedLocale(int $id, string $locale): bool
    {
        return $this->database->table('cms_content_record_translation')->where('record_id', $id)->where('publication_state', CmsPublicationState::Published->value)->whereRaw('locale <> ?', [$locale])->exists();
    }

    /**
     * Returns ordered target IDs for the requested sources and fields
     *
     * @param list<int> $sourceIds
     * @param list<int> $fieldIds
     * @return array<int,array<int,list<int>>>
     */
    public function relationIdsForSources(array $sourceIds, array $fieldIds): array
    {
        if ($sourceIds === [] || $fieldIds === []) {
            return [];
        }
        $rows = $this->database->table('cms_content_record_relation')
            ->select(['source_record_id', 'field_id', 'target_record_id'])
            ->whereIn('source_record_id', $sourceIds)->whereIn('field_id', $fieldIds)
            ->orderBy('source_record_id')->orderBy('field_id')->orderBy('sort_order')->orderBy('id')->getArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['source_record_id']][(int) $row['field_id']][] = (int) $row['target_record_id'];
        }
        return $result;
    }

    /**
     * Replaces links while the caller holds the source root lock
     *
     * @param list<int> $targetIds
     */
    public function saveRelation(int $sourceId, int $sourceTypeId, int $fieldId, int $targetTypeId, array $targetIds): void
    {
        $this->database->table('cms_content_record_relation')->where('source_record_id', $sourceId)->where('field_id', $fieldId)->delete();
        foreach ($targetIds as $position => $targetId) {
            $this->database->table('cms_content_record_relation')->insert([
                'source_record_id' => $sourceId,
                'source_type_id' => $sourceTypeId,
                'field_id' => $fieldId,
                'target_record_id' => $targetId,
                'target_type_id' => $targetTypeId,
                'sort_order' => $position,
            ]);
        }
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function relationTargets(int $typeId, array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $this->database->table('cms_content_record')->where('type_id', $typeId)->whereIn('id', $ids);
        $rows = ($lock ? $query->lockForUpdate() : $query)->getArray();
        return array_column($rows, null, 'id');
    }

    /**
     * Returns labels for selected target records in one locale
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    public function relationTargetLabels(int $typeId, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->database->table('cms_content_record r')
            ->select(['r.id', 'r.owner_id', 'r.deleted_at'])
            ->selectRaw("COALESCE(NULLIF(t.title, ''), d.title) AS title")
            ->joinRaw('cms_content_record_translation t', 't.record_id = r.id AND t.locale = ?', [$locale], 'LEFT')
            ->joinRaw('system_language l', 'l.is_default = 1', [], 'LEFT')
            ->joinRaw('cms_content_record_translation d', 'd.record_id = r.id AND d.locale = l.code', [], 'LEFT')
            ->where('r.type_id', $typeId)->whereIn('r.id', $ids)->where('r.deleted_at', null)->getArray();
        return array_column($rows, null, 'id');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function record(int $typeId, int $id): ?array
    {
        return $this->database->table('cms_content_record')->where('type_id', $typeId)->where('id', $id)->first();
    }

    /**
     * @param list<int> $sourceIds
     * @param list<int> $fieldIds
     * @return list<array<string,mixed>>
     */
    public function relationRowsForSources(array $sourceIds, array $fieldIds, string $locale): array
    {
        if ($sourceIds === [] || $fieldIds === []) {
            return [];
        }

        return $this->database->table('cms_content_record_relation relation')
            ->select(['relation.source_record_id', 'relation.field_id', 'relation.sort_order', 'r.id', 'r.owner_id', 'r.deleted_at'])
            ->selectRaw("COALESCE(NULLIF(t.title, ''), d.title) AS title")
            ->joinOn('cms_content_record r', 'r.id', '=', 'relation.target_record_id')
            ->joinRaw('cms_content_record_translation t', 't.record_id = r.id AND t.locale = ?', [$locale], 'LEFT')
            ->joinRaw('system_language l', 'l.is_default = 1', [], 'LEFT')
            ->joinRaw('cms_content_record_translation d', 'd.record_id = r.id AND d.locale = l.code', [], 'LEFT')
            ->whereIn('relation.source_record_id', $sourceIds)
            ->whereIn('relation.field_id', $fieldIds)
            ->orderBy('relation.source_record_id')
            ->orderBy('relation.field_id')
            ->orderBy('relation.sort_order')
            ->orderBy('relation.id')
            ->getArray();
    }

    /**
     * Restricts a record query to sources linked to any requested target
     *
     * @param int|list<int> $targetIds
     */
    public function filterByRelation(QueryBuilder $query, int $fieldId, int|array $targetIds): QueryBuilder
    {
        $targetIds = is_int($targetIds) ? [$targetIds] : $targetIds;
        if ($targetIds === []) {
            return $query->whereRaw('1 = 0');
        }
        $placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
        return $query->whereRaw(
            'EXISTS (SELECT 1 FROM cms_content_record_relation relation'
            . ' WHERE relation.source_record_id = a.id AND relation.field_id = ? AND relation.target_record_id IN (' . $placeholders . '))',
            [$fieldId, ...$targetIds],
        );
    }

    /**
     * Restricts sources to records with or without a stored relation
     */
    public function filterByRelationPresence(QueryBuilder $query, int $fieldId, bool $present): QueryBuilder
    {
        return $query->whereRaw(
            ($present ? 'EXISTS' : 'NOT EXISTS')
            . ' (SELECT 1 FROM cms_content_record_relation relation'
            . ' WHERE relation.source_record_id = a.id AND relation.field_id = ?)',
            [$fieldId],
        );
    }

    /**
     * Builds the stored scalar value expression for one field
     *
     * @param array<string,mixed> $field
     */
    public function scalarFieldExpression(array $field): string
    {
        $storage = $this->types->get((string) $field['kind'])->storage();
        if ($storage === null) {
            throw new InvalidArgumentException('Field has no scalar storage.');
        }
        $id = (int) $field['id'];
        $alias = 'fv' . $id;
        $localized = (int) $field['localized'] === 1;
        $table = $localized ? 'cms_content_record_translation_value' : 'cms_content_record_value';
        $condition = $alias . '.record_id = a.id AND ' . $alias . '.field_id = ' . $id
            . ($localized ? ' AND ' . $alias . '.translation_id = t.id' : '');
        return 'SELECT ' . $alias . '.value_' . $storage . ' FROM ' . $table . ' ' . $alias . ' WHERE ' . $condition;
    }

    /**
     * Applies a supported scalar field filter to an existing record query
     *
     * @param array<string,mixed> $field
     */
    public function filterByScalar(QueryBuilder $query, array $field, string $filter): QueryBuilder
    {
        $storage = $this->types->get((string) $field['kind'])->storage();
        $expression = '(' . $this->scalarFieldExpression($field) . ')';
        if (isset(self::FILTERS[$storage])) {
            if (preg_match(self::FILTERS[$storage][1], $filter) !== 1) {
                throw new InvalidArgumentException('Invalid typed field filter.');
            }
            return $query->whereRaw($expression . ' = ?', [$storage === 'datetime' ? str_replace('T', ' ', $filter) . ':00' : $filter]);
        }
        if ($storage === 'boolean') {
            if (!in_array($filter, ['0', '1'], true)) {
                throw new InvalidArgumentException('Invalid boolean field filter.');
            }
            return $query->whereRaw($expression . ' = ?', [(int) $filter]);
        }
        if ($storage === 'option') {
            foreach ($field['options'] as $option) {
                if ($option['code'] === $filter && $option['archived_at'] === null) {
                    return $query->whereRaw($expression . ' = ?', [(int) $option['id']]);
                }
            }
            throw new InvalidArgumentException('Invalid option field filter.');
        }
        if ($storage === 'text') {
            return $query->whereRaw($expression . ' = ?', [$filter]);
        }
        throw new InvalidArgumentException('Unsupported scalar field filter.');
    }

    /**
     * Applies inclusive bounds to a numeric or temporal field
     *
     * @param array<string,mixed> $field
     * @param array{from?:string,to?:string} $bounds
     */
    public function filterByRange(QueryBuilder $query, array $field, array $bounds): QueryBuilder
    {
        if ($bounds === []) {
            return $query;
        }
        $storage = $this->types->get((string) $field['kind'])->storage();
        if (!isset(self::FILTERS[$storage])) {
            throw new InvalidArgumentException('Unsupported range field.');
        }

        $alias = 'range_value';
        $localized = (int) $field['localized'] === 1;
        $table = $localized ? 'cms_content_record_translation_value' : 'cms_content_record_value';
        $conditions = [$alias . '.record_id = a.id', $alias . '.field_id = ?'];
        $bindings = [(int) $field['id']];
        if ($localized) {
            $conditions[] = $alias . '.translation_id = t.id';
        }
        foreach ($bounds as $bound => $value) {
            $pattern = $storage === 'datetime'
                ? '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?$/D'
                : self::FILTERS[$storage][1];
            if (preg_match($pattern, $value) !== 1) {
                throw new InvalidArgumentException('Invalid typed range bound.');
            }
            if (in_array($storage, ['date', 'datetime'], true)) {
                $withSeconds = $storage === 'datetime' && substr_count($value, ':') === 1 ? $value . ':00' : $value;
                $format = $storage === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s';
                $date = DateTimeImmutable::createFromFormat('!' . $format, $withSeconds);
                if ($date === false || $date->format($format) !== $withSeconds) {
                    throw new InvalidArgumentException('Invalid typed range bound.');
                }
            }
            $conditions[] = $alias . '.value_' . $storage . ($bound === 'from' ? ' >= ?' : ' <= ?');
            $bindings[] = $storage === 'datetime'
                ? str_replace('T', ' ', $value) . (substr_count($value, ':') === 1 ? ':00' : '')
                : $value;
        }

        return $query->whereRaw('EXISTS (SELECT 1 FROM ' . $table . ' ' . $alias . ' WHERE ' . implode(' AND ', $conditions) . ')', $bindings);
    }

    /**
     * Searches authorized target candidates for the relation option endpoint
     *
     * @return list<array<string,mixed>>
     */
    public function searchRelationTargets(
        int $typeId,
        string $locale,
        string $search,
        int $limit,
        int $offset,
        ?int $ownerId = null,
        ?int $excludeId = null,
        ?int $selectedId = null,
        bool $includeDeleted = false,
    ): array {
        $title = "COALESCE(NULLIF(t.title, ''), d.title)";
        $query = $this->database->table('cms_content_record r')
            ->select(['r.id', 'r.owner_id', 'r.deleted_at'])
            ->selectRaw($title . ' AS title')
            ->joinRaw('cms_content_record_translation t', 't.record_id = r.id AND t.locale = ?', [$locale], 'LEFT')
            ->joinRaw('system_language l', 'l.is_default = 1', [], 'LEFT')
            ->joinRaw('cms_content_record_translation d', 'd.record_id = r.id AND d.locale = l.code', [], 'LEFT')
            ->where('r.type_id', $typeId)
            ->orderBy('title')->orderBy('r.id');
        if (!$includeDeleted) {
            $query = $query->where('r.deleted_at', null);
        }
        if ($search !== '') {
            $condition = $title . ' LIKE ?';
            $bindings = ['%' . $search . '%'];
            if (ctype_digit($search)) {
                $condition = '(' . $condition . ' OR r.id = ?)';
                $bindings[] = (int) $search;
            }
            $query = $query->whereRaw($condition, $bindings);
        }
        if ($ownerId !== null) {
            $query = $query->where('r.owner_id', $ownerId);
        }
        if ($excludeId !== null) {
            $query = $query->whereRaw('r.id <> ?', [$excludeId]);
        }
        if ($selectedId !== null) {
            $query = $query->where('r.id', $selectedId);
        }
        return $query->limit($limit, $offset)->getArray();
    }
}
