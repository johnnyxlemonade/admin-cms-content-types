<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Pridava nezavisle prepinace systemove galerie a priloh k obsahovemu typu
 */
final readonly class AddContentTypeSystemMedia implements MigrationInterface
{
    /**
     * Vraci stabilni identitu upgrade migrace
     */
    public static function identifier(): string
    {
        return '20261109100000_add_cms_content_type_system_media';
    }

    /**
     * Zachova existujici typy s obema funkcemi vypnutymi
     */
    public function up(Schema $schema): void
    {
        $schema->table('cms_content_type', static function (TableBlueprint $table): void {
            $table->boolean('gallery_enabled')->default(0);
            $table->boolean('attachments_enabled')->default(0);
        });
    }
}
