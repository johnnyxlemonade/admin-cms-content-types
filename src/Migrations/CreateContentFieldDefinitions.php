<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

final readonly class CreateContentFieldDefinitions implements MigrationInterface
{
    public static function identifier(): string
    {
        return '20261106100000_create_cms_content_field_definitions';
    }

    public function up(Schema $schema): void
    {
        $schema->table('cms_content_type', static function (TableBlueprint $table): void {
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(1);
            $table->unsignedInteger('revision')->default(1);
        });

        $schema->create('cms_content_layout_row', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('type_id');
            $table->unsignedInteger('sort_order');
            $table->unique(['id', 'type_id'], 'uq_cms_layout_type');
            $table->index(['type_id', 'sort_order'], 'idx_cms_layout_order');
            $table->foreign('type_id', 'fk_cms_layout_type')->references('id')->on('cms_content_type')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

        $schema->create('cms_content_field', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('type_id');
            $table->string('code', 64);
            $table->string('name', 180);
            $table->string('kind', 20);
            $table->unsignedBigInteger('row_id')->nullable();
            $table->unsignedInteger('span')->default(12);
            $table->foreign(['row_id', 'type_id'], 'fk_cms_field_row')->references(['id', 'type_id'])->on('cms_content_layout_row')->restrictOnDelete()->restrictOnUpdate();
            $table->boolean('required')->default(0);
            $table->boolean('localized')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('help')->nullable();
            // An unset HTML ID is derived from the field code.
            $table->string('css_class', 255)->nullable();
            $table->string('html_id', 128)->nullable();
            $table->unsignedInteger('min_length')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->decimal('min_number', 24, 8)->nullable();
            $table->decimal('max_number', 24, 8)->nullable();
            $table->unsignedInteger('decimal_places')->nullable();
            $table->datetime('min_date')->nullable();
            $table->datetime('max_date')->nullable();
            $table->text('default_text')->nullable();
            $table->bigInteger('default_integer')->nullable();
            $table->decimal('default_decimal', 24, 8)->nullable();
            $table->boolean('default_boolean')->nullable();
            $table->date('default_date')->nullable();
            $table->datetime('default_datetime')->nullable();
            $table->string('default_option', 64)->nullable();
            $table->datetime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['type_id', 'code'], 'uq_cms_content_field_code');
            $table->index(['type_id', 'archived_at', 'sort_order'], 'idx_cms_content_field_order');
            $table->foreign('type_id', 'fk_cms_field_type')->references('id')->on('cms_content_type')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

        $schema->create('cms_content_field_option', static function (TableBlueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('field_id');
            $table->string('code', 64);
            $table->string('name', 180);
            $table->unsignedInteger('sort_order')->default(0);
            $table->datetime('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['field_id', 'code'], 'uq_cms_content_field_option_code');
            $table->index(['field_id', 'archived_at', 'sort_order'], 'idx_cms_content_option_order');
            $table->foreign('field_id', 'fk_cms_option_field')->references('id')->on('cms_content_field')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        });

    }
}
