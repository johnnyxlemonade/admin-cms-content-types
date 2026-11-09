<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Cms\Content\CmsContentSchema;
use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

final readonly class CreateContentRecords implements MigrationInterface
{
    public static function identifier(): string
    {
        return '20261106120000_create_cms_content_records';
    }

    public function up(Schema $schema): void
    {
        // Composite foreign keys prevent cross-type fields and cross-field options.
        $schema->table('cms_content_field', static function (TableBlueprint $table): void {
            $table->unique(['id', 'type_id'], 'uq_cms_field_type_identity');
        });
        $schema->table('cms_content_field_option', static function (TableBlueprint $table): void {
            $table->unique(['id', 'field_id'], 'uq_cms_option_field_identity');
        });
        $schema->create('cms_content_record', static function (TableBlueprint $table): void {
            CmsContentSchema::root($table, 'cms_content_record');
            $table->unsignedBigInteger('type_id');
            $table->unique(['id', 'type_id'], 'uq_cms_record_type');
            $table->index(['type_id', 'deleted_at', 'owner_id'], 'idx_cms_record_scope');
            $table->foreign('type_id', 'fk_cms_record_type')->references('id')->on('cms_content_type')->restrictOnDelete()->restrictOnUpdate();
        });
        $schema->create('cms_content_record_translation', static function (TableBlueprint $table): void {
            CmsContentSchema::translation($table, 'cms_content_record', 'record_id');
            $table->unique(['id', 'record_id'], 'uq_cms_record_translation_identity');
        });
        $schema->create('cms_content_record_translation_tag', static function (TableBlueprint $table): void {
            CmsContentSchema::tags($table, 'cms_content_record', 'cms_content_record_translation');
        });
        // Separate value tables enforce uniqueness without a nullable locale.
        foreach (['cms_content_record_value' => false, 'cms_content_record_translation_value' => true] as $name => $localized) {
            $prefix = $localized ? 'ctv' : 'cv';
            $schema->create($name, static function (TableBlueprint $table) use ($localized, $prefix): void {
                $table->id();
                $table->unsignedBigInteger('record_id');
                $table->unsignedBigInteger('type_id');
                $table->unsignedBigInteger('field_id');
                if ($localized) {
                    $table->unsignedBigInteger('translation_id');
                    $table->unique(['translation_id', 'field_id'], 'uq_' . $prefix . '_value');
                    $table->foreign(['translation_id', 'record_id'], 'fk_' . $prefix . '_translation')->references(['id', 'record_id'])->on('cms_content_record_translation')->restrictOnDelete()->restrictOnUpdate();
                } else {
                    $table->unique(['record_id', 'field_id'], 'uq_' . $prefix . '_value');
                }
                $table->foreign(['record_id', 'type_id'], 'fk_' . $prefix . '_record')->references(['id', 'type_id'])->on('cms_content_record')->restrictOnDelete()->restrictOnUpdate();
                $table->foreign(['field_id', 'type_id'], 'fk_' . $prefix . '_field')->references(['id', 'type_id'])->on('cms_content_field')->restrictOnDelete()->restrictOnUpdate();
                $table->longText('value_text')->nullable();
                $table->bigInteger('value_integer')->nullable();
                $table->decimal('value_decimal', 24, 8)->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->date('value_date')->nullable();
                $table->datetime('value_datetime')->nullable();
                $table->unsignedBigInteger('value_option')->nullable();
                $table->foreign(['value_option', 'field_id'], 'fk_' . $prefix . '_option')->references(['id', 'field_id'])->on('cms_content_field_option')->restrictOnDelete()->restrictOnUpdate();
                foreach (['integer', 'decimal', 'boolean', 'date', 'datetime', 'option'] as $storage) {
                    $table->index(['field_id', 'value_' . $storage, 'record_id'], 'idx_' . $prefix . '_' . $storage);
                }
                $table->engine('InnoDB');
                $table->charset('utf8mb4');
            });
        }
    }
}
