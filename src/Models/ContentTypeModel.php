<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\DataGrid\Query\QueryPage;
use Lemonade\Framework\Database\Model;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Nacita strankovany prehled definic obsahovych typu
 */
final class ContentTypeModel extends Model
{
    protected string $table = 'cms_content_type';

    /**
     * Vyhleda definice a seradi je podle povoleneho sloupce
     *
     * @return QueryPage<array{id:int,code:string,name:string,enabled:int,deleted_at:string|null}>
     */
    public function listForDataGrid(DataGridQuery $query): QueryPage
    {
        $sort = $query->sortKey() === 'code' ? 'code' : 'name';
        /**
         * @var list<array{id:int,code:string,name:string,enabled:int,deleted_at:string|null}> $rows
         */
        $rows = $this->filtered($query->search(), $query->filter('status'))
            ->select(['id', 'code', 'name', 'enabled', 'deleted_at'])
            ->orderBy($sort, $query->sortDirection() === 'desc' ? 'DESC' : 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($query->pageSize(), ($query->page() - 1) * $query->pageSize())
            ->getArray();

        return new QueryPage(
            $rows,
            $query->page(),
            $query->pageSize(),
            $this->filtered($query->search(), $query->filter('status'))->countAllResults(),
        );
    }

    /**
     * Pouzije totozne vyhledavani pro radky i celkovy pocet
     */
    private function filtered(string $search, ?string $status): QueryBuilder
    {
        $builder = $status === 'deleted'
            ? $this->query()->whereNotNull('deleted_at')
            : $this->query()->where('deleted_at', null);
        $search = trim($search);
        if ($search !== '') {
            $builder = $builder->whereRaw('(name LIKE ? OR code LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        }

        return $builder;
    }
}
