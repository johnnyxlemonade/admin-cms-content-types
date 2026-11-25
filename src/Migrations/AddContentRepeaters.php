<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Adds repeatable field definitions, ordered items and relational text values
 */
final readonly class AddContentRepeaters implements MigrationInterface
{
    /**
     * Returns the stable migration identifier
     */
    public static function identifier(): string
    {
        return '20261125100000_add_cms_content_repeaters';
    }

    /**
     * Creates the first repeatable group schema
     */
    public function up(Schema $schema): void
    {
        $schema->create('cms_content_repeater_field', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('field_id');
            $table->string('code', 64);
            $table->string('name', 180);
            $table->string('kind', 20);
            $table->boolean('required')->default(0);
            $table->boolean('localized')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->datetime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['field_id', 'code'], 'uq_cms_repeater_field_code');
            $table->unique(['id', 'field_id'], 'uq_cms_repeater_field_identity');
            $table->index(['field_id', 'archived_at', 'sort_order'], 'idx_cms_repeater_field_order');
            $table->foreign('field_id', 'fk_cms_repeater_field_parent')
                ->references('id')->on('cms_content_field')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

        $schema->create('cms_content_repeater_item', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('type_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
            $table->unique(['id', 'record_id', 'field_id'], 'uq_cms_repeater_item_identity');
            $table->index(['record_id', 'field_id', 'sort_order'], 'idx_cms_repeater_item_order');
            $table->foreign(['record_id', 'type_id'], 'fk_cms_repeater_item_record')
                ->references(['id', 'type_id'])->on('cms_content_record')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['field_id', 'type_id'], 'fk_cms_repeater_item_field')
                ->references(['id', 'type_id'])->on('cms_content_field')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

        $schema->create('cms_content_repeater_item_value', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('child_field_id');
            $table->longText('value_text')->nullable();
            $table->unique(['item_id', 'child_field_id'], 'uq_cms_repeater_item_value');
            $table->foreign(['item_id', 'record_id', 'field_id'], 'fk_cms_repeater_value_item')
                ->references(['id', 'record_id', 'field_id'])->on('cms_content_repeater_item')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign(['child_field_id', 'field_id'], 'fk_cms_repeater_value_field')
                ->references(['id', 'field_id'])->on('cms_content_repeater_field')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

        $schema->create('cms_content_repeater_item_translation_value', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedBigInteger('child_field_id');
            $table->unsignedBigInteger('translation_id');
            $table->longText('value_text')->nullable();
            $table->unique(['translation_id', 'item_id', 'child_field_id'], 'uq_cms_repeater_translation_value');
            $table->foreign(['item_id', 'record_id', 'field_id'], 'fk_cms_repeater_translation_item')
                ->references(['id', 'record_id', 'field_id'])->on('cms_content_repeater_item')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign(['child_field_id', 'field_id'], 'fk_cms_repeater_translation_field')
                ->references(['id', 'field_id'])->on('cms_content_repeater_field')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['translation_id', 'record_id'], 'fk_cms_repeater_translation')
                ->references(['id', 'record_id'])->on('cms_content_record_translation')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });
    }
}
