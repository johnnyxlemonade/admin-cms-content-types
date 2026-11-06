<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Admin\Cms\Content\CmsDataGridProjection;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Pripojuje typovane hodnoty indexovanymi poddotazy bez zasahu do CMS scopes
 */
final readonly class RecordGridProjection implements CmsDataGridProjection
{
    public const FILTERS = [
        'integer' => ['number', '/^-?(?:0|[1-9][0-9]{0,15})$/D'],
        'decimal' => ['number', '/^-?(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D'],
        'date' => ['date', '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D'],
        'datetime' => ['datetime-local', '/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D'],
    ];

    /**
     * Prijima pouze ulozene definice a explicitni registry storage druhu
     *
     * @param list<array<string,mixed>> $fields
     */
    public function __construct(private array $fields, private FieldTypeRegistry $types) {}

    public function sorts(): array
    {
        $sorts = [];
        foreach ($this->fields as $field) {
            if ($field['kind'] !== 'richtext') {
                $sorts['field_' . $field['id']] = 'field_' . (int) $field['id'];
            }
        }
        return $sorts;
    }

    public function apply(QueryBuilder $builder, DataGridQuery $query): QueryBuilder
    {
        foreach ($this->fields as $field) {
            $id = (int) $field['id'];
            $alias = 'fv' . $id;
            $storage = $this->types->get((string) $field['kind'])->storage();
            $localized = (int) $field['localized'] === 1;
            $table = $localized ? 'cms_content_record_translation_value' : 'cms_content_record_value';
            $condition = $alias . '.record_id = a.id AND ' . $alias . '.field_id = ' . $id . ($localized ? ' AND ' . $alias . '.translation_id = t.id' : '');
            $subquery = 'SELECT ' . $alias . '.value_' . $storage . ' FROM ' . $table . ' ' . $alias . ' WHERE ' . $condition;
            $builder = $builder->selectRaw('(' . $subquery . ') AS field_' . $id);
            $filter = $query->filter('field_' . $id);
            if ($filter !== null && isset(self::FILTERS[$storage])) {
                if (preg_match(self::FILTERS[$storage][1], $filter) !== 1) {
                    throw new \InvalidArgumentException('Invalid typed field filter.');
                }
                $builder = $builder->whereRaw('(' . $subquery . ') = ?', [$storage === 'datetime' ? str_replace('T', ' ', $filter) . ':00' : $filter]);
            }
            if ($filter !== null && $storage === 'boolean' && in_array($filter, ['0', '1'], true)) {
                $builder = $builder->whereRaw('(' . $subquery . ') = ?', [(int) $filter]);
            }
            if ($filter !== null && $storage === 'option') {
                foreach ($field['options'] as $option) {
                    if ($option['code'] === $filter && $option['archived_at'] === null) {
                        $builder = $builder->whereRaw('(' . $subquery . ') = ?', [(int) $option['id']]);
                    }
                }
            }
        }
        return $builder;
    }
}
