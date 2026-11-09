<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

final readonly class CreateContentTypes implements MigrationInterface
{
    public static function identifier(): string
    {
        return '20261106090000_create_cms_content_types';
    }

    public function up(Schema $schema): void
    {
        $schema->create('cms_content_type', static function (TableBlueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('name', 180);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['code'], 'uq_cms_content_type_code');
            $table->index(['name', 'id'], 'idx_cms_content_type_name');
            $table->index(['deleted_at', 'name', 'id'], 'idx_cms_content_type_deleted_name');
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);
    }
}
