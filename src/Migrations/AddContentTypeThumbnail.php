<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Pridava vypinatelny systemovy hlavni obrazek k obsahovemu typu
 */
final readonly class AddContentTypeThumbnail implements MigrationInterface
{
    /**
     * Vraci stabilni identitu navazujici migrace
     */
    public static function identifier(): string
    {
        return '20261109110000_add_cms_content_type_thumbnail';
    }

    /**
     * Ponecha hlavni obrazek existujicich typu vypnuty
     */
    public function up(Schema $schema): void
    {
        $schema->table('cms_content_type', static function (TableBlueprint $table): void {
            $table->boolean('thumbnail_enabled')->default(0);
        });
    }
}
