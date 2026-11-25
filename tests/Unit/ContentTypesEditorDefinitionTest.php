<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\ContentTypes\Editor\ContentTypesEditorDefinition;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the structural layout of the Content Type editor
 */
final class ContentTypesEditorDefinitionTest extends TestCase
{
    /**
     * Separates settings and field editing while preserving the basic field layout
     */
    public function testSeparatesSettingsAndFieldEditorIntoTabs(): void
    {
        $database = $this->database();
        $router = new Router();
        $action = ControllerAction::for(self::class, 'testSeparatesSettingsAndFieldEditorIntoTabs');
        $router->getNamed('admin.cms.module.index', '/admin/cms/{module}', $action);
        $router->getNamed('admin.cms.module.edit', '/admin/cms/{module}/edit/{id}', $action);
        $router->postNamed('admin.cms.module.ajax.entity', '/admin/api/cms/{module}/{id}', $action);
        $definition = new ContentTypesEditorDefinition(
            new UrlGenerator($router),
            new FieldTypeRegistry([]),
            new AdminEditorFieldRenderer(),
            new LanguageRegistry($database, new CacheManager(new ArrayCacheItemPool())),
            new DefinitionModel($database),
        );

        $editor = $definition->create(['id' => 6]);
        $header = $editor->header();
        self::assertNotNull($header);
        $headerActions = $header->actions();
        self::assertCount(2, $headerActions);
        self::assertSame(
            ['admin.common.back', 'admin.common.save'],
            array_map(static fn($action): ?string => $action->labelKey(), $headerActions),
        );
        self::assertFalse($headerActions[0]->submit());
        self::assertTrue($headerActions[1]->submit());
        self::assertTrue($headerActions[1]->primary());

        self::assertSame([], $editor->blocks());
        $tabs = $editor->tabs();
        self::assertCount(2, $tabs);
        self::assertSame(['settings', 'fields'], array_map(static fn($tab): string => $tab->id(), $tabs));
        self::assertSame(
            ['content-types.editor.tabs.settings', 'content-types.editor.tabs.fields'],
            array_map(static fn($tab): ?string => $tab->labelKey(), $tabs),
        );
        self::assertTrue($tabs[0]->default());
        self::assertFalse($tabs[1]->default());

        $sections = array_values(array_filter(
            $tabs[0]->blocks(),
            static fn($block): bool => $block instanceof SectionBlock && $block->id() === 'type',
        ));
        self::assertCount(1, $sections);
        $groups = array_values(array_filter(
            $sections[0]->blocks(),
            static fn($block): bool => $block instanceof FieldGroupBlock,
        ));
        self::assertCount(1, $groups);
        $columns = $groups[0]->columns();
        self::assertSame(
            ['name', 'code', 'description', 'sort_order', 'enabled'],
            array_map(static function ($column): string {
                $field = $column->field();
                self::assertInstanceOf(AdminEditorFieldDefinition::class, $field);

                return $field->name();
            }, $columns),
        );
        $xlWidths = array_map(
            static fn($column): int => $column->breakpoints()['xl'] ?? 0,
            $columns,
        );
        self::assertSame([6, 6, 6, 3, 3], $xlWidths);
        self::assertSame(12, array_sum(array_slice($xlWidths, 0, 2)));
        self::assertSame(12, array_sum(array_slice($xlWidths, 2)));

        $fieldEditorBlocks = $tabs[1]->blocks();
        self::assertCount(2, $fieldEditorBlocks);
        self::assertInstanceOf(AdminEditorFieldDefinition::class, $fieldEditorBlocks[0]);
        self::assertSame('definition', $fieldEditorBlocks[0]->name());
        self::assertInstanceOf(CustomViewBlock::class, $fieldEditorBlocks[1]);
        self::assertSame('content-types::builder', $fieldEditorBlocks[1]->view());
    }

    /**
     * Creates database doubles for locale and definition reads
     */
    private function database(): Database
    {
        $connection = self::createStub(ConnectionInterface::class);
        $connection->method('select')->willReturn([
            ['code' => 'cs', 'name' => 'Čeština', 'is_default' => 1],
        ]);
        $result = self::createStub(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([]);
        $driver = self::createStub(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $driver->method('query')->willReturn($result);

        return new Database($connection, $driver);
    }
}
