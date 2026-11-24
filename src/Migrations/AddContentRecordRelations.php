<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Adds relation field settings and ordered record links
 */
final readonly class AddContentRecordRelations implements MigrationInterface
{
    public static function identifier(): string
    {
        return '20261124100000_add_cms_content_record_relations';
    }

    public function up(Schema $schema): void
    {
        $schema->table('cms_content_field', static function (TableBlueprint $table): void {
            $table->unsignedBigInteger('relation_target_type_id')->nullable();
            $table->boolean('relation_multiple')->default(0);
            $table->unique(['id', 'type_id', 'relation_target_type_id'], 'uq_cms_field_relation_identity');
            $table->foreign('relation_target_type_id', 'fk_cms_field_relation_target_type')
                ->references('id')->on('cms_content_type')->restrictOnDelete()->restrictOnUpdate();
        });

        $schema->create('cms_content_record_relation', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_record_id');
            $table->unsignedBigInteger('source_type_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('target_record_id');
            $table->unsignedBigInteger('target_type_id');
            $table->unsignedInteger('sort_order');
            $table->unique(['source_record_id', 'field_id', 'target_record_id'], 'uq_cms_record_relation_target');
            $table->index(['source_record_id', 'field_id', 'sort_order'], 'idx_cms_record_relation_order');
            $table->index(['field_id', 'target_record_id', 'source_record_id'], 'idx_cms_record_relation_reverse');
            $table->foreign(['source_record_id', 'source_type_id'], 'fk_cms_relation_source')
                ->references(['id', 'type_id'])->on('cms_content_record')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['field_id', 'source_type_id', 'target_type_id'], 'fk_cms_relation_field')
                ->references(['id', 'type_id', 'relation_target_type_id'])->on('cms_content_field')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['target_record_id', 'target_type_id'], 'fk_cms_relation_target')
                ->references(['id', 'type_id'])->on('cms_content_record')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });
    }
}
