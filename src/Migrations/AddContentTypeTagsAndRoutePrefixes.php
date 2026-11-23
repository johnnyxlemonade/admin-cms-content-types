<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Adds optional tags and localized canonical path prefixes to content types
 */
final readonly class AddContentTypeTagsAndRoutePrefixes implements MigrationInterface
{
    /**
     * Returns the stable migration identifier
     */
    public static function identifier(): string
    {
        return '20261123090000_add_cms_content_type_tags_and_route_prefixes';
    }

    /**
     * Adds the type flag and normalized locale-specific prefix table
     */
    public function up(Schema $schema): void
    {
        $schema->table('cms_content_type', static function (TableBlueprint $table): void {
            $table->boolean('tags_enabled')->default(0);
        });

        $schema->create('cms_content_type_route_prefix', static function (TableBlueprint $table): void {
            $table->unsignedBigInteger('type_id');
            $table->string('locale', 10);
            $table->string('path_prefix', 180);
            $table->unique(['type_id', 'locale'], 'uq_cms_content_type_route_prefix');
            $table->foreign('type_id', 'fk_cms_content_type_route_prefix_type')
                ->references('id')->on('cms_content_type')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('locale', 'fk_cms_content_type_route_prefix_locale')
                ->references('code')->on('system_language')->cascadeOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });
    }
}
