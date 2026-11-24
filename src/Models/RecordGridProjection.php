<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Admin\Cms\Content\CmsDataGridProjection;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Applies Content Type field projections and filters to the Admin grid
 */
final readonly class RecordGridProjection implements CmsDataGridProjection
{
    /**
     * Receives the fields and shared record query implementation
     *
     * @param list<array<string,mixed>> $fields
     */
    public function __construct(
        private array $fields,
        private FieldTypeRegistry $types,
        private RecordModel $records,
    ) {}

    public function sorts(): array
    {
        $sorts = [];
        foreach ($this->fields as $field) {
            if ($field['kind'] === 'relation') {
                continue;
            }
            if ($this->types->get((string) $field['kind'])->storage() === null) {
                continue;
            }
            if ($field['kind'] !== 'richtext') {
                $sorts['field_' . $field['id']] = 'field_' . (int) $field['id'];
            }
        }
        return $sorts;
    }

    public function apply(QueryBuilder $builder, DataGridQuery $query): QueryBuilder
    {
        foreach ($this->fields as $field) {
            if ($field['kind'] === 'relation') {
                $filter = $query->filter('field_' . $field['id']);
                if ($filter !== null) {
                    $targetId = filter_var($filter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if (is_int($targetId)) {
                        $builder = $this->records->filterByRelation($builder, (int) $field['id'], $targetId);
                    } else {
                        $builder = $builder->whereRaw('1 = 0');
                    }
                }
                continue;
            }
            $storage = $this->types->get((string) $field['kind'])->storage();
            if ($storage === null) {
                continue;
            }
            $id = (int) $field['id'];
            $builder = $builder->selectRaw('(' . $this->records->scalarFieldExpression($field) . ') AS field_' . $id);
            $filter = $query->filter('field_' . $id);
            if ($filter !== null) {
                $builder = $this->records->filterByScalar($builder, $field, $filter);
            }
        }
        return $builder;
    }
}
