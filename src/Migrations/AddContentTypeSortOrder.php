<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Adds deterministic navigation order to Content Type definitions
 */
final readonly class AddContentTypeSortOrder implements MigrationInterface
{
    /**
     * Creates the migration with access to existing definitions
     */
    public function __construct(private Database $database) {}

    /**
     * Returns the stable migration identifier
     */
    public static function identifier(): string
    {
        return '20261125110000_add_cms_content_type_sort_order';
    }

    /**
     * Adds the order and preserves the previous identifier-based sequence
     */
    public function up(Schema $schema): void
    {
        $schema->table('cms_content_type', static function (TableBlueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0);
            $table->index(['sort_order', 'id'], 'idx_cms_content_type_sort');
        });

        $types = $this->database->table('cms_content_type')
            ->select(['id'])
            ->orderBy('id', 'ASC')
            ->getArray();
        foreach ($types as $sortOrder => $type) {
            $this->database->table('cms_content_type')
                ->where('id', (int) $type['id'])
                ->set(['sort_order' => $sortOrder])
                ->update();
        }
    }
}
