<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Public;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Provides publication-scoped read access to dynamic Content Types
 */
final class PublishedRecordReadModel
{
    /**
     * @var array<string,RecordModule>|null
     */
    private ?array $types = null;

    /**
     * @var array<int,list<array<string,mixed>>>
     */
    private ?array $fields = null;

    /**
     * @var array<int,array<string,array<int,PublishedRecord>>>
     */
    private array $hydratedRecords = [];

    /**
     * Creates the published record reader with its existing CMS dependencies
     */
    public function __construct(
        private readonly Database $database,
        private readonly RecordModel $records,
        private readonly DefinitionModel $definitions,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly FileReadModel $files,
        private readonly PublicCmsUrlBuilder $urls,
        private readonly PublicModuleStateResolverInterface $modules,
    ) {}

    /**
     * Returns one visible record or null when the record is unavailable
     */
    public function record(string $typeCode, int $id, PublicLocaleResolution $locale): ?PublishedRecord
    {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return null;
        }
        $type = $this->type($typeCode);
        $language = $locale->getLocale();
        if (!$type->enabled() || $language === null) {
            return null;
        }
        $row = $this->publishedQuery($type, $language)->where('a.id', $id)->first();
        return $row === null ? null : $this->hydrate($type, [$row], $locale)[0];
    }

    /**
     * Returns a page of visible records filtered by field codes
     *
     * @param array<string,string> $filters Scalar field codes and values
     * @param array<string,int|list<int>> $relations Relation field codes and target record IDs
     * @param array<string,bool> $relationPresence Relation fields that must contain or lack a target
     * @param list<string> $searchFields Text field codes searched together with the title
     * @param list<string> $searchRelations Relation field codes whose visible target titles are searched
     * @param array<string,array{from?:string,to?:string}> $ranges Numeric, date or publication time bounds
     */
    public function collection(
        string $typeCode,
        PublicLocaleResolution $locale,
        array $filters = [],
        array $relations = [],
        int $page = 1,
        int $perPage = 25,
        ?string $tag = null,
        ?string $search = null,
        array $searchFields = [],
        array $searchRelations = [],
        array $ranges = [],
        ?string $sortBy = null,
        string $sortDirection = 'desc',
        array $relationPresence = [],
    ): PublishedRecordCollection {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size must be positive.');
        }
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return new PublishedRecordCollection([], 0, $page, $perPage);
        }
        $type = $this->type($typeCode);
        $language = $locale->getLocale();
        $fields = array_column($this->fields($type), null, 'code');
        foreach ($filters as $code => $value) {
            $field = $fields[$code] ?? null;
            if ($field === null || $this->fieldTypes->get((string) $field['kind'])->storage() === null) {
                throw new InvalidArgumentException('Unknown scalar field filter: ' . $code);
            }
        }
        foreach ($relations as $code => $targets) {
            if (($fields[$code]['kind'] ?? null) !== 'relation') {
                throw new InvalidArgumentException('Unknown relation field: ' . $code);
            }
        }
        foreach ($relationPresence as $code => $present) {
            if (($fields[$code]['kind'] ?? null) !== 'relation' || !is_bool($present)) {
                throw new InvalidArgumentException('Unknown relation presence filter: ' . $code);
            }
        }
        $direction = strtoupper($sortDirection);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        foreach ($searchFields as $code) {
            if (!isset($fields[$code]) || !in_array($fields[$code]['kind'], ['text', 'textarea', 'richtext', 'integer', 'decimal'], true)) {
                throw new InvalidArgumentException('Unknown search field: ' . $code);
            }
        }
        foreach ($searchRelations as $code) {
            if (($fields[$code]['kind'] ?? null) !== 'relation') {
                throw new InvalidArgumentException('Unknown relation search field: ' . $code);
            }
        }
        foreach ($ranges as $code => $bounds) {
            if ($code !== 'publishedAt' && (
                !isset($fields[$code])
                || $this->fieldTypes->get((string) $fields[$code]['kind'])->storage() === null
            )) {
                throw new InvalidArgumentException('Unknown range field: ' . $code);
            }
            if (array_diff(array_keys($bounds), ['from', 'to']) !== []) {
                throw new InvalidArgumentException('Range accepts only from and to bounds.');
            }
        }
        $sortColumn = match ($sortBy) {
            null, 'publishedAt' => 't.published_at',
            'title' => 't.title',
            default => $this->sortFieldExpression($fields, $sortBy),
        };
        if (!$type->enabled() || $language === null) {
            return new PublishedRecordCollection([], 0, $page, $perPage);
        }
        if ($tag !== null && !$type->tagsEnabled()) {
            return new PublishedRecordCollection([], 0, $page, $perPage);
        }
        $query = $this->publishedQuery($type, $language, $tag);
        foreach ($filters as $code => $value) {
            $query = $this->records->filterByScalar($query, $fields[$code], $value);
        }
        foreach ($relations as $code => $targets) {
            $field = $fields[$code];
            $ids = is_int($targets) ? [$targets] : $targets;
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) {
                    throw new InvalidArgumentException('Relation targets must be positive record IDs.');
                }
            }
            $targetType = $this->typeById((int) $field['relation_target_type_id']);
            if ($targetType === null || $ids === []) {
                return new PublishedRecordCollection([], 0, $page, $perPage);
            }
            $visible = $this->publishedQuery($targetType, $language)->whereIn('a.id', $ids)->getArray();
            $visibleIds = array_map(static fn(array $row): int => (int) $row['id'], $visible);
            $query = $this->records->filterByRelation($query, (int) $field['id'], $visibleIds);
        }
        foreach ($relationPresence as $code => $present) {
            $query = $this->records->filterByRelationPresence($query, (int) $fields[$code]['id'], $present);
        }
        $term = trim($search ?? '');
        if ($term !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
            $expressions = ['t.title LIKE ? ESCAPE \'!\''];
            $bindings = [$pattern];
            foreach ($searchFields as $code) {
                $expression = '(' . $this->records->scalarFieldExpression($fields[$code]) . ')';
                if (in_array($fields[$code]['kind'], ['integer', 'decimal'], true)) {
                    $expression = 'CAST(' . $expression . ' AS CHAR)';
                }
                $expressions[] = $expression . ' LIKE ? ESCAPE \'!\'';
                $bindings[] = $pattern;
            }
            foreach ($searchRelations as $code) {
                $field = $fields[$code];
                $targetType = $this->typeById((int) $field['relation_target_type_id']);
                if ($targetType === null) {
                    continue;
                }
                $targets = $this->publishedQuery($targetType, $language)
                    ->whereRaw("t.title LIKE ? ESCAPE '!'", [$pattern])
                    ->getArray();
                $targetIds = array_map(static fn(array $row): int => (int) $row['id'], $targets);
                if ($targetIds === []) {
                    continue;
                }
                $sources = $this->database->table('cms_content_record_relation')
                    ->select(['source_record_id'])
                    ->where('field_id', (int) $field['id'])
                    ->whereIn('target_record_id', $targetIds)
                    ->getArray();
                $sourceIds = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['source_record_id'], $sources)));
                if ($sourceIds !== []) {
                    $expressions[] = 'a.id IN (' . implode(', ', array_fill(0, count($sourceIds), '?')) . ')';
                    array_push($bindings, ...$sourceIds);
                }
            }
            $query = $query->whereRaw('(' . implode(' OR ', $expressions) . ')', $bindings);
        }
        foreach ($ranges as $code => $bounds) {
            $query = $code === 'publishedAt'
                ? $this->publicationRange($query, $bounds)
                : $this->records->filterByRange($query, $fields[$code], $bounds);
        }
        $total = $query->countAllResults();
        if ($total === 0 || $page - 1 > intdiv($total - 1, $perPage)) {
            return new PublishedRecordCollection([], $total, $page, $perPage);
        }
        if ($sortBy !== null && !in_array($sortBy, ['publishedAt', 'title'], true)) {
            $query = $query->selectRaw($sortColumn . ' AS sort_value');
            $sortColumn = 'sort_value';
        }
        $query = $query->orderBy($sortColumn, $direction);
        if ($sortBy !== null && str_ends_with($sortBy, '.title')) {
            $query = $query->orderBy('t.title', $direction);
        }
        $rows = $query->orderBy('a.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->getArray();
        return new PublishedRecordCollection($this->hydrate($type, $rows, $locale), $total, $page, $perPage);
    }

    /**
     * Selects visible record identifiers without counting or hydrating records
     *
     * @param array<string,string> $filters Scalar field codes and values
     * @param array<string,int|list<int>> $relations Relation field codes and target record IDs
     * @param array<string,bool> $relationPresence Relation fields that must contain or lack a target
     * @param array<string,array{from?:string,to?:string}> $ranges Numeric, date or publication time bounds
     * @return list<int>
     */
    public function selectIds(
        string $typeCode,
        PublicLocaleResolution $locale,
        array $filters = [],
        array $relations = [],
        array $relationPresence = [],
        array $ranges = [],
        ?string $sortBy = null,
        string $sortDirection = 'desc',
        ?int $limit = null,
    ): array {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('Record limit must be positive.');
        }
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return [];
        }

        $type = $this->type($typeCode);
        $language = $locale->getLocale();
        $fields = array_column($this->fields($type), null, 'code');
        foreach ($filters as $code => $value) {
            $field = $fields[$code] ?? null;
            if ($field === null || $this->fieldTypes->get((string) $field['kind'])->storage() === null) {
                throw new InvalidArgumentException('Unknown scalar field filter: ' . $code);
            }
        }
        foreach ($relations as $code => $targets) {
            if (($fields[$code]['kind'] ?? null) !== 'relation') {
                throw new InvalidArgumentException('Unknown relation field: ' . $code);
            }
        }
        foreach ($relationPresence as $code => $present) {
            if (($fields[$code]['kind'] ?? null) !== 'relation' || !is_bool($present)) {
                throw new InvalidArgumentException('Unknown relation presence filter: ' . $code);
            }
        }
        foreach ($ranges as $code => $bounds) {
            if ($code !== 'publishedAt' && (
                !isset($fields[$code])
                || $this->fieldTypes->get((string) $fields[$code]['kind'])->storage() === null
            )) {
                throw new InvalidArgumentException('Unknown range field: ' . $code);
            }
            if (array_diff(array_keys($bounds), ['from', 'to']) !== []) {
                throw new InvalidArgumentException('Range accepts only from and to bounds.');
            }
        }
        $direction = strtoupper($sortDirection);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        $sortColumn = match ($sortBy) {
            null, 'publishedAt' => 't.published_at',
            'title' => 't.title',
            default => $this->sortFieldExpression($fields, $sortBy),
        };
        if (!$type->enabled() || $language === null) {
            return [];
        }

        $query = $this->publishedScope($type, $language)->select(['a.id']);
        foreach ($filters as $code => $value) {
            $query = $this->records->filterByScalar($query, $fields[$code], $value);
        }
        foreach ($relations as $code => $targets) {
            $field = $fields[$code];
            $ids = is_int($targets) ? [$targets] : $targets;
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) {
                    throw new InvalidArgumentException('Relation targets must be positive record IDs.');
                }
            }
            $targetType = $this->typeById((int) $field['relation_target_type_id']);
            if ($targetType === null || $ids === []) {
                return [];
            }
            $visibleIds = $this->visibleRecordIds($targetType, $ids, $language);
            if ($visibleIds === []) {
                return [];
            }
            $query = $this->records->filterByRelation($query, (int) $field['id'], $visibleIds);
        }
        foreach ($relationPresence as $code => $present) {
            $query = $this->records->filterByRelationPresence($query, (int) $fields[$code]['id'], $present);
        }
        foreach ($ranges as $code => $bounds) {
            $query = $code === 'publishedAt'
                ? $this->publicationRange($query, $bounds)
                : $this->records->filterByRange($query, $fields[$code], $bounds);
        }
        if ($sortBy !== null && !in_array($sortBy, ['publishedAt', 'title'], true)) {
            $query = $query->selectRaw($sortColumn . ' AS sort_value');
            $sortColumn = 'sort_value';
        }
        $query = $query->orderBy($sortColumn, $direction);
        if ($sortBy !== null && str_ends_with($sortBy, '.title')) {
            $query = $query->orderBy('t.title', $direction);
        }
        $query = $query->orderBy('a.id', 'DESC');
        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $query->getArray(),
        );
    }

    /**
     * Hydrates explicitly selected records of several Content Types in shared batches
     *
     * @param array<string,list<int>> $recordIdsByType
     * @return array<string,list<PublishedRecord>>
     */
    public function recordsForTypes(array $recordIdsByType, PublicLocaleResolution $locale): array
    {
        $result = array_fill_keys(array_keys($recordIdsByType), []);
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return $result;
        }
        $language = $locale->getLocale();
        if ($language === null) {
            return $result;
        }

        $types = [];
        $missingIdsByType = [];
        foreach ($recordIdsByType as $typeCode => $recordIds) {
            $type = $this->type($typeCode);
            $types[$type->id()] = $type;
            if (!$type->enabled()) {
                continue;
            }
            foreach ($recordIds as $recordId) {
                if (!is_int($recordId) || $recordId < 1) {
                    throw new InvalidArgumentException('Record identifiers must be positive integers.');
                }
                if (!isset($this->hydratedRecords[$type->id()][$language][$recordId])) {
                    $missingIdsByType[$type->id()][] = $recordId;
                }
            }
            $missingIdsByType[$type->id()] = array_values(array_unique($missingIdsByType[$type->id()] ?? []));
        }

        $rowsByType = $this->publishedRowsForTypes($types, $missingIdsByType, $language);
        $this->hydrateRowsForTypes($types, $rowsByType, $locale);

        foreach ($recordIdsByType as $typeCode => $recordIds) {
            $type = $this->type($typeCode);
            foreach (array_values(array_unique($recordIds)) as $recordId) {
                $record = $this->hydratedRecords[$type->id()][$language][$recordId] ?? null;
                if ($record !== null) {
                    $result[$typeCode][] = $record;
                }
            }
        }

        return $result;
    }

    /**
     * Selects visible source identifiers of several types that reference one target
     *
     * @param array<string,string> $relationFieldsByType
     * @return array<string,list<int>>
     */
    public function referencingIdsForTypes(
        array $relationFieldsByType,
        int $targetId,
        PublicLocaleResolution $locale,
    ): array {
        if ($targetId < 1) {
            throw new InvalidArgumentException('Relation target must be a positive record ID.');
        }
        $result = array_fill_keys(array_keys($relationFieldsByType), []);
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return $result;
        }
        $language = $locale->getLocale();
        if ($language === null) {
            return $result;
        }

        $fields = [];
        $sourceTypeIds = [];
        $targetTypes = [];
        foreach ($relationFieldsByType as $typeCode => $fieldCode) {
            $sourceType = $this->type($typeCode);
            $field = $this->relationField($sourceType, $fieldCode);
            $targetType = $this->typeById((int) $field['relation_target_type_id']);
            if (!$sourceType->enabled() || $targetType === null) {
                continue;
            }
            $fields[(int) $field['id']] = $typeCode;
            $sourceTypeIds[] = $sourceType->id();
            $targetTypes[$targetType->id()] = $targetType;
        }
        foreach ($targetTypes as $targetType) {
            if ($this->visibleRecordIds($targetType, [$targetId], $language) === []) {
                return $result;
            }
        }
        if ($fields === []) {
            return $result;
        }

        $rows = $this->database->table('cms_content_record_relation relation')
            ->select(['a.id', 'relation.field_id'])
            ->joinOn('cms_content_record a', 'a.id', '=', 'relation.source_record_id')
            ->joinOn('cms_content_record_translation t', 't.record_id', '=', 'a.id')
            ->joinOn('cms_content_type type', 'type.id', '=', 'a.type_id')
            ->joinOn('system_language language', 'language.code', '=', 't.locale')
            ->whereIn('a.type_id', array_values(array_unique($sourceTypeIds)))
            ->whereIn('relation.field_id', array_keys($fields))
            ->where('relation.target_record_id', $targetId)
            ->where('a.deleted_at', null)
            ->where('type.enabled', 1)
            ->where('type.deleted_at', null)
            ->where('language.enabled', 1)
            ->where('t.publication_state', CmsPublicationState::Published->value)
            ->where('t.published_at !=', null)
            ->where('t.published_at <=', date('Y-m-d H:i:s'))
            ->whereRaw("TRIM(t.title) <> ''")
            ->where('t.locale', $language)
            ->orderBy('relation.sort_order')
            ->orderBy('relation.id')
            ->getArray();
        foreach ($rows as $row) {
            $typeCode = $fields[(int) $row['field_id']] ?? null;
            if ($typeCode !== null) {
                $result[$typeCode][] = (int) $row['id'];
            }
        }

        return $result;
    }

    /**
     * Accepts stored scalar fields and the first related title as sort keys
     *
     * @param array<string,array<string,mixed>> $fields
     */
    private function sortFieldExpression(array $fields, string $code): string
    {
        if (str_ends_with($code, '.title')) {
            $relationCode = substr($code, 0, -strlen('.title'));
            $relation = $fields[$relationCode] ?? null;
            if ($relation !== null && $relation['kind'] === 'relation') {
                return '(SELECT related_title.title FROM cms_content_record_relation related_link'
                    . ' JOIN cms_content_record_translation related_title ON related_title.record_id = related_link.target_record_id'
                    . ' AND related_title.locale = t.locale'
                    . ' WHERE related_link.source_record_id = a.id AND related_link.field_id = ' . (int) $relation['id']
                    . ' ORDER BY related_link.sort_order, related_link.id LIMIT 1)';
            }
        }
        $field = $fields[$code] ?? null;
        if ($field === null || !in_array($field['kind'], ['text', 'integer', 'decimal', 'date', 'datetime', 'option'], true)) {
            throw new InvalidArgumentException('Unsupported sort field: ' . $code);
        }

        return '(' . $this->records->scalarFieldExpression($field) . ')';
    }

    /**
     * Applies inclusive publication time bounds to the visible translation
     *
     * @param array{from?:string,to?:string} $bounds
     */
    private function publicationRange(QueryBuilder $query, array $bounds): QueryBuilder
    {
        foreach ($bounds as $bound => $value) {
            if (preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2})?$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid publication time bound.');
            }
            $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date === false || $date->format($format) !== $value) {
                throw new InvalidArgumentException('Invalid publication time bound.');
            }
            $normalized = str_replace('T', ' ', $value);
            $normalized .= strlen($value) === 10 ? ($bound === 'from' ? ' 00:00:00' : ' 23:59:59') : ':00';
            $query = $query->where('t.published_at ' . ($bound === 'from' ? '>=' : '<='), $normalized);
        }

        return $query;
    }

    /**
     * Returns the newest visible records of one type except the current record
     *
     * @return list<PublishedRecord>
     */
    public function others(
        string $typeCode,
        int $id,
        PublicLocaleResolution $locale,
        int $limit = 5,
        ?string $tag = null,
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('Record limit must be positive.');
        }
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return [];
        }

        $type = $this->type($typeCode);
        $language = $locale->getLocale();
        if (!$type->enabled() || $language === null || ($tag !== null && !$type->tagsEnabled())) {
            return [];
        }

        $query = $this->publishedQuery($type, $language, $tag)->where('a.id !=', $id);

        $rows = $query->orderBy('t.published_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->limit($limit)
            ->getArray();

        return $this->hydrate($type, $rows, $locale);
    }

    /**
     * Reports whether a record is visible in any active content locale
     */
    public function isPublicInAnyLocale(string $typeCode, int $id): bool
    {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return false;
        }
        $type = $this->type($typeCode);

        return $type->enabled() && $this->publishedScope($type)
            ->where('a.id', $id)
            ->countAllResults() > 0;
    }

    /**
     * Returns visible targets of one source relation in stored order
     *
     * @return list<PublishedRecord>
     */
    public function related(string $sourceTypeCode, int $sourceId, string $fieldCode, PublicLocaleResolution $locale): array
    {
        return $this->relatedForRecords($sourceTypeCode, [$sourceId], $fieldCode, $locale)[$sourceId] ?? [];
    }

    /**
     * Returns visible targets for every requested source identifier
     *
     * @param list<int> $sourceIds
     * @return array<int,list<PublishedRecord>>
     */
    public function relatedForRecords(string $sourceTypeCode, array $sourceIds, string $fieldCode, PublicLocaleResolution $locale): array
    {
        return $this->relatedFieldsForRecords(
            $sourceTypeCode,
            $sourceIds,
            [$fieldCode],
            $locale,
        )[$fieldCode];
    }

    /**
     * Returns visible target identifiers without hydrating target records
     *
     * @param list<int> $sourceIds
     * @return array<int,list<int>>
     */
    public function relatedIdsForRecords(
        string $sourceTypeCode,
        array $sourceIds,
        string $fieldCode,
        PublicLocaleResolution $locale,
    ): array {
        return $this->relatedFieldIdsForRecords(
            $sourceTypeCode,
            $sourceIds,
            [$fieldCode],
            $locale,
        )[$fieldCode];
    }

    /**
     * Returns visible relation target identifiers for several fields in one relation query
     *
     * @param list<int> $sourceIds
     * @param list<string> $fieldCodes
     * @return array<string,array<int,list<int>>>
     */
    public function relatedFieldIdsForRecords(
        string $sourceTypeCode,
        array $sourceIds,
        array $fieldCodes,
        PublicLocaleResolution $locale,
    ): array {
        return $this->relatedFieldIdsForRecordSets([
            $sourceTypeCode => [
                'recordIds' => $sourceIds,
                'fieldCodes' => $fieldCodes,
            ],
        ], $locale)[$sourceTypeCode];
    }

    /**
     * Returns visible relation target identifiers for several Content Types in shared batches
     *
     * @param array<string,array{recordIds:list<int>,fieldCodes:list<string>}> $recordSets
     * @return array<string,array<string,array<int,list<int>>>>
     */
    public function relatedFieldIdsForRecordSets(
        array $recordSets,
        PublicLocaleResolution $locale,
    ): array {
        $result = [];
        foreach ($recordSets as $typeCode => $recordSet) {
            foreach ($recordSet['fieldCodes'] as $fieldCode) {
                $result[$typeCode][$fieldCode] = array_fill_keys($recordSet['recordIds'], []);
            }
        }
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return $result;
        }
        $language = $locale->getLocale();
        if ($language === null) {
            return $result;
        }

        $fields = [];
        $sourceIds = [];
        foreach ($recordSets as $typeCode => $recordSet) {
            $sourceType = $this->type($typeCode);
            if (!$sourceType->enabled() || $recordSet['recordIds'] === []) {
                continue;
            }
            array_push(
                $sourceIds,
                ...$this->visibleRecordIds($sourceType, $recordSet['recordIds'], $language),
            );
            foreach ($recordSet['fieldCodes'] as $fieldCode) {
                $field = $this->relationField($sourceType, $fieldCode);
                $fields[(int) $field['id']] = [
                    'typeCode' => $typeCode,
                    'fieldCode' => $fieldCode,
                    'targetTypeId' => (int) $field['relation_target_type_id'],
                ];
            }
        }
        if ($sourceIds === [] || $fields === []) {
            return $result;
        }

        $links = $this->records->relationRowsForSources(
            array_values(array_unique($sourceIds)),
            array_keys($fields),
            $language,
        );
        $targetIdsByType = [];
        foreach ($links as $link) {
            $field = $fields[(int) $link['field_id']] ?? null;
            if ($field !== null) {
                $targetIdsByType[$field['targetTypeId']][] = (int) $link['id'];
            }
        }
        $visibleTargets = [];
        foreach ($targetIdsByType as $targetTypeId => $targetIds) {
            $targetType = $this->typeById($targetTypeId);
            if ($targetType === null) {
                continue;
            }
            $visibleTargets[$targetTypeId] = array_fill_keys(
                $this->visibleRecordIds($targetType, array_values(array_unique($targetIds)), $language),
                true,
            );
        }
        foreach ($links as $link) {
            $field = $fields[(int) $link['field_id']] ?? null;
            if ($field === null) {
                continue;
            }
            $targetId = (int) $link['id'];
            if (isset($visibleTargets[$field['targetTypeId']][$targetId])) {
                $result[$field['typeCode']][$field['fieldCode']][(int) $link['source_record_id']][] = $targetId;
            }
        }

        return $result;
    }

    /**
     * Returns visible relation targets grouped by field and source identifier
     *
     * @param list<int> $sourceIds
     * @param list<string> $fieldCodes
     * @return array<string,array<int,list<PublishedRecord>>>
     */
    public function relatedFieldsForRecords(
        string $sourceTypeCode,
        array $sourceIds,
        array $fieldCodes,
        PublicLocaleResolution $locale,
    ): array {
        $result = [];
        foreach ($fieldCodes as $fieldCode) {
            $result[$fieldCode] = array_fill_keys($sourceIds, []);
        }
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return $result;
        }
        $sourceType = $this->type($sourceTypeCode);
        $fields = [];
        foreach ($fieldCodes as $fieldCode) {
            $field = $this->relationField($sourceType, $fieldCode);
            $fields[(int) $field['id']] = ['code' => $fieldCode, 'definition' => $field];
        }
        $language = $locale->getLocale();
        if (!$sourceType->enabled() || $language === null || $sourceIds === [] || $fields === []) {
            return $result;
        }
        $visibleSourceIds = $this->visibleSourceIds($sourceType, $sourceIds, $language);
        if ($visibleSourceIds === []) {
            return $result;
        }
        $links = $this->records->relationRowsForSources($visibleSourceIds, array_keys($fields), $language);
        if ($links === []) {
            return $result;
        }

        foreach ($fields as $fieldId => $field) {
            $targetType = $this->typeById((int) $field['definition']['relation_target_type_id']);
            if ($targetType === null) {
                continue;
            }
            $fieldLinks = array_values(array_filter(
                $links,
                static fn(array $link): bool => (int) $link['field_id'] === $fieldId,
            ));
            $targetIds = array_values(array_unique(array_map(
                static fn(array $row): int => (int) $row['id'],
                $fieldLinks,
            )));
            $targets = [];
            foreach ($this->publishedRecordsByIds($targetType, $targetIds, $locale) as $target) {
                $targets[$target->getId()] = $target;
            }
            foreach ($fieldLinks as $link) {
                $target = $targets[(int) $link['id']] ?? null;
                if ($target !== null) {
                    $result[$field['code']][(int) $link['source_record_id']][] = $target;
                }
            }
        }

        return $result;
    }

    /**
     * @param list<int> $sourceIds
     * @return list<int>
     */
    private function visibleSourceIds(RecordModule $sourceType, array $sourceIds, string $language): array
    {
        return $this->visibleRecordIds($sourceType, $sourceIds, $language);
    }

    /**
     * Returns visible identifiers while reusing already hydrated records
     *
     * @param list<int> $recordIds
     * @return list<int>
     */
    private function visibleRecordIds(RecordModule $type, array $recordIds, string $language): array
    {
        $visibleIds = [];
        $uncheckedIds = [];
        foreach ($recordIds as $recordId) {
            if (isset($this->hydratedRecords[$type->id()][$language][$recordId])) {
                $visibleIds[] = $recordId;
            } else {
                $uncheckedIds[] = $recordId;
            }
        }
        if ($uncheckedIds !== []) {
            $visibleRows = $this->publishedScope($type, $language)
                ->select(['a.id'])
                ->whereIn('a.id', $uncheckedIds)
                ->getArray();
            array_push(
                $visibleIds,
                ...array_map(static fn(array $row): int => (int) $row['id'], $visibleRows),
            );
        }

        return $visibleIds;
    }

    /**
     * Returns a page of published sources that reference one target
     */
    public function referencing(
        string $sourceTypeCode,
        string $fieldCode,
        int $targetId,
        PublicLocaleResolution $locale,
        int $page = 1,
        int $perPage = 25,
    ): PublishedRecordCollection {
        return $this->collection($sourceTypeCode, $locale, relations: [$fieldCode => $targetId], page: $page, perPage: $perPage);
    }

    /**
     * Yields visible record candidates with their canonical URL and last update time
     *
     * @return iterable<PublishedRecordSitemapEntry>
     */
    public function sitemapEntries(PublicLocaleResolution $locale): iterable
    {
        $language = $locale->getLocale();
        if ($language === null || !$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return;
        }
        foreach ($this->types() as $type) {
            if (!$type->enabled()) {
                continue;
            }
            $query = $this->publishedScope($type, $language)
                ->select(['route.path'])
                ->selectRaw('GREATEST(a.updated_at, t.updated_at, route.updated_at) AS last_modified_at')
                ->whereRaw('route.path IS NOT NULL');
            foreach ($query->cursor() as $row) {
                yield new PublishedRecordSitemapEntry(
                    $this->urls->build($locale, $language, (string) $row['path']),
                    new DateTimeImmutable((string) $row['last_modified_at']),
                    $type->typeCode(),
                );
            }
        }
    }

    /**
     * Returns lightweight published references with one scalar label field
     *
     * @return list<PublishedRecordReference>
     */
    public function references(
        string $typeCode,
        PublicLocaleResolution $locale,
        string $labelField,
        ?string $sortField = null,
        ?string $groupField = null,
    ): array {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return [];
        }
        $type = $this->type($typeCode);
        $language = $locale->getLocale();
        $fields = array_column($this->fields($type), null, 'code');
        $field = $fields[$labelField] ?? null;
        if ($field === null || $this->fieldTypes->get((string) $field['kind'])->storage() === null) {
            throw new InvalidArgumentException('Unknown reference label field: ' . $labelField);
        }
        if (!$type->enabled() || $language === null) {
            return [];
        }

        $query = $this->publishedScope($type, $language)
            ->select(['a.id', 't.title'])
            ->selectRaw('route.path AS canonical_path')
            ->selectRaw('(' . $this->records->scalarFieldExpression($field) . ') AS reference_label');
        if ($groupField !== null) {
            $group = $fields[$groupField] ?? null;
            if ($group === null || $this->fieldTypes->get((string) $group['kind'])->storage() === null) {
                throw new InvalidArgumentException('Unknown reference group field: ' . $groupField);
            }
            $groupExpression = $this->records->scalarFieldExpression($group);
            if ($this->fieldTypes->get((string) $group['kind'])->storage() === 'option') {
                $groupExpression = '(SELECT field_option.code FROM cms_content_field_option field_option'
                    . ' WHERE field_option.id = (' . $groupExpression . '))';
            }
            $query = $query->selectRaw(
                '(' . $groupExpression . ') AS reference_group',
            );
        }
        if ($sortField !== null) {
            $sort = $fields[$sortField] ?? null;
            if ($sort === null || $this->fieldTypes->get((string) $sort['kind'])->storage() === null) {
                throw new InvalidArgumentException('Unknown reference sort field: ' . $sortField);
            }
            $query = $query
                ->selectRaw('(' . $this->records->scalarFieldExpression($sort) . ') AS reference_sort')
                ->orderBy('reference_sort', 'ASC')
                ->orderBy('a.id', 'ASC');
        } else {
            $query = $query
                ->orderBy('t.published_at', 'DESC')
                ->orderBy('a.id', 'DESC');
        }
        $rows = $query->getArray();
        $references = [];
        foreach ($rows as $row) {
            $title = (string) $row['title'];
            $label = trim((string) ($row['reference_label'] ?? ''));
            $path = $row['canonical_path'];
            $references[] = new PublishedRecordReference(
                id: (int) $row['id'],
                title: $title,
                label: $label !== '' ? $label : $title,
                publicUrl: $path === null ? null : $this->urls->build($locale, $language, (string) $path),
                group: isset($row['reference_group']) ? (string) $row['reference_group'] : null,
            );
        }

        return $references;
    }

    /**
     * Returns one active relation field or rejects an unknown code
     *
     * @return array<string,mixed>
     */
    private function relationField(RecordModule $type, string $code): array
    {
        foreach ($this->fields($type) as $field) {
            if ($field['code'] === $code && $field['kind'] === 'relation') {
                return $field;
            }
        }
        throw new InvalidArgumentException('Unknown relation field: ' . $code);
    }

    /**
     * Returns a configured type or rejects an unknown code
     */
    private function type(string $code): RecordModule
    {
        return $this->types()[$code] ?? throw new InvalidArgumentException('Unknown Content Type: ' . $code);
    }

    /**
     * Returns an active type by its internal identifier
     */
    private function typeById(int $id): ?RecordModule
    {
        foreach ($this->types() as $type) {
            if ($type->id() === $id && $type->enabled()) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Returns the configured Content Types for this reader scope
     *
     * @return array<string,RecordModule>
     */
    private function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ($this->records->modules() as $type) {
                $this->types[$type->typeCode()] = $type;
            }
        }
        return $this->types;
    }

    /**
     * Returns active definitions of one Content Type
     *
     * @return list<array<string,mixed>>
     */
    private function fields(RecordModule $type): array
    {
        if ($this->fields === null) {
            $this->fields = [];
            $definitions = $this->definitions->fieldsForTypes(array_values(array_map(
                static fn(RecordModule $recordType): int => $recordType->id(),
                $this->types(),
            )));
            foreach ($definitions as $typeId => $fields) {
                $this->fields[$typeId] = array_values(array_filter(
                    $fields,
                    static fn(array $field): bool => $field['archived_at'] === null,
                ));
            }
        }

        return $this->fields[$type->id()] ?? [];
    }

    /**
     * Restricts the query to currently published and visible translations
     */
    private function publishedQuery(RecordModule $type, string $locale, ?string $tag = null): QueryBuilder
    {
        $display = ['a.recommended', 'a.show_author', 'a.sharing_enabled', 'a.show_published_at',
            'a.show_thumbnail', 'a.show_reading_time', 'a.show_tags'];
        $query = $this->publishedScope($type, $locale)
            ->select(['a.id', 'a.author_name', ...$display, 't.title', 't.page_title',
                't.meta_description', 't.published_at'])
            ->selectRaw('t.id AS translation_id, t.updated_at AS translation_updated_at, route.path AS canonical_path');

        return $tag === null ? $query : $query->whereRaw(
            'EXISTS (SELECT 1 FROM cms_content_record_translation_tag tag WHERE tag.translation_id = t.id AND tag.name = ?)',
            [$tag],
        );
    }

    /**
     * Applies the publication, language, type and canonical route joins shared by public queries
     */
    private function publishedScope(RecordModule $type, ?string $locale = null): QueryBuilder
    {
        $query = $this->database->table('cms_content_record a')
            ->joinOn('cms_content_record_translation t', 't.record_id', '=', 'a.id')
            ->joinOn('cms_content_type type', 'type.id', '=', 'a.type_id')
            ->joinOn('system_language language', 'language.code', '=', 't.locale')
            ->joinRaw('cms_route route', 'route.entity_id = a.id AND route.module_code = ? AND route.locale = t.locale AND route.deleted_at IS NULL AND route.path <> ?', [$type->code(), ''], 'LEFT')
            ->where('a.type_id', $type->id())->where('a.deleted_at', null)
            ->where('type.enabled', 1)->where('type.deleted_at', null)
            ->where('language.enabled', 1)
            ->where('t.publication_state', CmsPublicationState::Published->value)
            ->where('t.published_at !=', null)->where('t.published_at <=', date('Y-m-d H:i:s'))
            ->whereRaw("TRIM(t.title) <> ''");

        return $locale === null ? $query : $query->where('t.locale', $locale);
    }

    /**
     * Loads visible base rows and canonical routes for several Content Types
     *
     * @param array<int,RecordModule> $types
     * @param array<int,list<int>> $recordIdsByType
     * @return array<int,list<array<string,mixed>>>
     */
    private function publishedRowsForTypes(array $types, array $recordIdsByType, string $language): array
    {
        $recordIds = [];
        $requestedTypes = [];
        foreach ($recordIdsByType as $typeId => $typeRecordIds) {
            foreach ($typeRecordIds as $recordId) {
                $recordIds[] = $recordId;
                $requestedTypes[$recordId] = $typeId;
            }
        }
        if ($recordIds === []) {
            return [];
        }

        $display = ['a.recommended', 'a.show_author', 'a.sharing_enabled', 'a.show_published_at',
            'a.show_thumbnail', 'a.show_reading_time', 'a.show_tags'];
        $rows = $this->database->table('cms_content_record a')
            ->select(['a.id', 'a.type_id', 'a.author_name', ...$display, 't.title', 't.page_title',
                't.meta_description', 't.published_at'])
            ->selectRaw('t.id AS translation_id, t.updated_at AS translation_updated_at')
            ->joinOn('cms_content_record_translation t', 't.record_id', '=', 'a.id')
            ->joinOn('cms_content_type type', 'type.id', '=', 'a.type_id')
            ->joinOn('system_language language', 'language.code', '=', 't.locale')
            ->whereIn('a.id', array_values(array_unique($recordIds)))
            ->whereIn('a.type_id', array_keys($recordIdsByType))
            ->where('a.deleted_at', null)
            ->where('type.enabled', 1)
            ->where('type.deleted_at', null)
            ->where('language.enabled', 1)
            ->where('t.publication_state', CmsPublicationState::Published->value)
            ->where('t.published_at !=', null)
            ->where('t.published_at <=', date('Y-m-d H:i:s'))
            ->whereRaw("TRIM(t.title) <> ''")
            ->where('t.locale', $language)
            ->getArray();
        if ($rows === []) {
            return [];
        }

        $routes = [];
        $moduleCodes = array_values(array_map(
            static fn(RecordModule $type): string => $type->code(),
            $types,
        ));
        $routeRows = $this->database->table('cms_route')
            ->select(['module_code', 'entity_id', 'path'])
            ->where('locale', $language)
            ->whereIn('module_code', $moduleCodes)
            ->whereIn('entity_id', array_values(array_unique($recordIds)))
            ->where('deleted_at', null)
            ->where('path !=', '')
            ->getArray();
        foreach ($routeRows as $route) {
            $routes[(string) $route['module_code']][(int) $route['entity_id']] = (string) $route['path'];
        }

        $rowsByType = [];
        foreach ($rows as $row) {
            $recordId = (int) $row['id'];
            $typeId = (int) $row['type_id'];
            $type = $types[$typeId] ?? null;
            if ($type === null || ($requestedTypes[$recordId] ?? null) !== $typeId) {
                continue;
            }
            $row['canonical_path'] = $routes[$type->code()][$recordId] ?? null;
            $rowsByType[$typeId][] = $row;
        }

        return $rowsByType;
    }

    /**
     * Hydrates visible records with scalar values, media and tags in batches
     *
     * @param list<array<string,mixed>> $rows
     * @return list<PublishedRecord>
     */
    private function hydrate(RecordModule $type, array $rows, PublicLocaleResolution $locale): array
    {
        if ($rows === []) {
            return [];
        }
        $language = $locale->getLocale() ?? throw new \LogicException('Missing public locale.');
        $requestedRows = $rows;
        $rows = array_values(array_filter(
            $rows,
            fn(array $row): bool => !isset($this->hydratedRecords[$type->id()][$language][(int) $row['id']]),
        ));
        if ($rows === []) {
            return array_map(
                fn(array $row): PublishedRecord => $this->hydratedRecords[$type->id()][$language][(int) $row['id']],
                $requestedRows,
            );
        }

        $this->hydrateRowsForTypes([$type->id() => $type], [$type->id() => $rows], $locale);

        return array_map(
            fn(array $row): PublishedRecord => $this->hydratedRecords[$type->id()][$language][(int) $row['id']],
            $requestedRows,
        );
    }

    /**
     * Hydrates prepared base rows with values, repeaters, media and tags in shared batches
     *
     * @param array<int,RecordModule> $types
     * @param array<int,list<array<string,mixed>>> $rowsByType
     */
    private function hydrateRowsForTypes(
        array $types,
        array $rowsByType,
        PublicLocaleResolution $locale,
    ): void {
        if ($rowsByType === []) {
            return;
        }
        $language = $locale->getLocale() ?? throw new \LogicException('Missing public locale.');
        $recordIdsByType = [];
        $fieldsByType = [];
        $repeaterDefinitions = [];
        foreach ($rowsByType as $typeId => $rows) {
            if ($rows === []) {
                continue;
            }
            $typeFields = $this->fields($types[$typeId]);
            $fieldsByType[$typeId] = $typeFields;
            $recordIdsByType[$typeId] = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            array_push($repeaterDefinitions, ...array_values(array_filter(
                $typeFields,
                static fn(array $field): bool => $field['kind'] === 'repeater' && $field['archived_at'] === null,
            )));
        }
        if ($recordIdsByType === []) {
            return;
        }

        $values = $this->records->fieldValuesForTypes($recordIdsByType, $language, $fieldsByType);
        $recordIds = array_values(array_unique(array_merge(...array_values($recordIdsByType))));
        $repeaterItems = $this->records->repeaterItemsForRecords(
            $recordIds,
            $language,
            $repeaterDefinitions,
        );
        $media = [];
        foreach ($recordIdsByType as $typeId => $typeRecordIds) {
            $type = $types[$typeId];
            $usages = $this->mediaUsages($type);
            foreach ($this->files->listForEntitiesUsages($type->code(), $typeRecordIds, array_keys($usages)) as $file) {
                $usage = $usages[$file->usage()];
                if ($usage['kind'] !== $file->kind()) {
                    continue;
                }
                if ($file->kind() === 'image' && ($file->assetId() === null || $file->sourceVersion() === null)) {
                    continue;
                }
                if ($file->kind() === 'file' && $file->storagePath() === null) {
                    continue;
                }
                $media[$file->entityId()][$usage['key']][] = $file;
            }
        }
        $translationIds = [];
        foreach ($rowsByType as $typeId => $rows) {
            if (!$types[$typeId]->tagsEnabled()) {
                continue;
            }
            array_push(
                $translationIds,
                ...array_map(static fn(array $row): int => (int) $row['translation_id'], $rows),
            );
        }
        $tags = [];
        if ($translationIds !== []) {
            $tagRows = $this->database->table('cms_content_record_translation_tag tag')
                ->select(['tag.translation_id', 'tag.name'])
                ->whereIn('tag.translation_id', $translationIds)
                ->orderBy('tag.translation_id')
                ->orderBy('tag.sort_order')
                ->orderBy('tag.id')
                ->getArray();
            foreach ($tagRows as $tag) {
                $tags[(int) $tag['translation_id']][] = (string) $tag['name'];
            }
        }

        foreach ($rowsByType as $typeId => $rows) {
            $type = $types[$typeId];
            $typeRepeaters = array_values(array_filter(
                $fieldsByType[$typeId],
                static fn(array $field): bool => $field['kind'] === 'repeater' && $field['archived_at'] === null,
            ));
            foreach ($rows as $row) {
                $recordId = (int) $row['id'];
                $recordFields = [];
                foreach ($values[$recordId] ?? [] as $field) {
                    $recordFields[$field->getCode()] = $field;
                }
                $repeatedFields = [];
                foreach ($typeRepeaters as $definition) {
                    $repeatedFields[(string) $definition['code']] = $repeaterItems[$recordId][(int) $definition['id']] ?? [];
                }
                $path = $row['canonical_path'];
                $url = $path === null ? null : $this->urls->build($locale, $language, (string) $path);
                $recordFiles = $media[$recordId] ?? [];
                $fieldFiles = [];
                foreach ($recordFiles as $key => $files) {
                    if (str_starts_with($key, 'field:')) {
                        $fieldFiles[substr($key, strlen('field:'))] = $files;
                    }
                }
                $recordTags = (bool) $row['show_tags'] ? ($tags[(int) $row['translation_id']] ?? []) : [];
                $this->hydratedRecords[$typeId][$language][$recordId] = new PublishedRecord(
                    id: $recordId,
                    typeCode: $type->typeCode(),
                    locale: $language,
                    title: (string) $row['title'],
                    publishedAt: new DateTimeImmutable((string) $row['published_at']),
                    updatedAt: new DateTimeImmutable((string) $row['translation_updated_at']),
                    publicUrl: $url,
                    fields: $recordFields,
                    repeatedFields: $repeatedFields,
                    thumbnail: (bool) $row['show_thumbnail'] ? ($recordFiles['thumbnail'][0] ?? null) : null,
                    gallery: $recordFiles['gallery'] ?? [],
                    attachments: $recordFiles['attachment'] ?? [],
                    fieldFiles: $fieldFiles,
                    tags: $recordTags,
                    pageTitle: $row['page_title'] === null ? null : (string) $row['page_title'],
                    metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
                    authorName: $row['author_name'] === null ? null : (string) $row['author_name'],
                    recommended: (bool) $row['recommended'],
                    showAuthor: (bool) $row['show_author'],
                    sharingEnabled: (bool) $row['sharing_enabled'],
                    showPublishedAt: (bool) $row['show_published_at'],
                    showThumbnail: (bool) $row['show_thumbnail'],
                    showReadingTime: (bool) $row['show_reading_time'],
                    showTags: (bool) $row['show_tags'],
                );
            }
        }
    }

    /**
     * Returns visible records while reusing records already hydrated in this reader scope
     *
     * @param list<int> $ids
     * @return list<PublishedRecord>
     */
    private function publishedRecordsByIds(
        RecordModule $type,
        array $ids,
        PublicLocaleResolution $locale,
    ): array {
        $language = $locale->getLocale() ?? throw new \LogicException('Missing public locale.');
        $missingIds = array_values(array_filter(
            $ids,
            fn(int $id): bool => !isset($this->hydratedRecords[$type->id()][$language][$id]),
        ));
        if ($missingIds !== []) {
            $rows = $this->publishedQuery($type, $language)->whereIn('a.id', $missingIds)->getArray();
            $this->hydrate($type, $rows, $locale);
        }

        $records = [];
        foreach ($ids as $id) {
            $record = $this->hydratedRecords[$type->id()][$language][$id] ?? null;
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Maps allowed file usages to public lookup keys
     *
     * @return array<string,array{kind:string,key:string}>
     */
    private function mediaUsages(RecordModule $type): array
    {
        $usages = [];
        if ($type->thumbnailEnabled()) {
            $usages['thumbnail'] = ['kind' => 'image', 'key' => 'thumbnail'];
        }
        if ($type->galleryEnabled()) {
            $usages['gallery'] = ['kind' => 'image', 'key' => 'gallery'];
        }
        if ($type->attachmentsEnabled()) {
            $usages['attachment'] = ['kind' => 'file', 'key' => 'attachment'];
        }
        foreach ($this->fields($type) as $field) {
            $definition = $this->fieldTypes->mediaDefinition($type->code(), $field);
            if ($definition !== null) {
                $usages[$definition->usage()] = ['kind' => $definition->kind(), 'key' => 'field:' . $field['code']];
            }
        }
        return $usages;
    }
}
