<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Overuje jediny explicitni katalog typu a soulad controls s normalizaci
 */
final class FieldTypeRegistryTest extends DefinitionTestCase
{
    /**
     * Kazdy typ ma stejna spolecna pole a pouze vlastni pravidla
     */
    public function testRegistryProvidesAllControlsAndRulesWithoutDuplicateCommonFields(): void
    {
        $types = $this->types();
        self::assertSame(['text', 'textarea', 'richtext', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'select'], $types->codes());
        self::assertSame($types->codes(), array_keys($types->inspectors()));
        self::assertSame($types->codes(), array_keys($types->previews()));
        foreach ($types->inspectors() as $code => $controls) {
            $names = array_map(static fn($control): string => $control->name(), $controls);
            self::assertSame($names, array_values(array_unique($names)));
            foreach (['name', 'code', 'required', 'localized', 'help', 'default_value', 'span'] as $name) {
                self::assertContains($name, $names);
            }
            foreach ($types->get($code)->ruleControls() as $name => $control) {
                if ($control !== null) {
                    self::assertContains($name, $names);
                }
            }
            self::assertArrayHasKey('default_' . $types->get($code)->storage(), $types->emptyValues());
        }
    }

    /** @return iterable<string,array{string,string,string|int}> */
    public static function defaults(): iterable
    {
        yield 'text' => ['text', 'Hello', 'Hello'];
        yield 'textarea' => ['textarea', "Hello\nworld", "Hello\nworld"];
        yield 'richtext' => ['richtext', '<p>Hello</p>', '<p>Hello</p>'];
        yield 'integer' => ['integer', '-12', -12];
        yield 'decimal' => ['decimal', '-12.50', '-12.50'];
        yield 'boolean' => ['boolean', '0', 0];
        yield 'date' => ['date', '2028-02-29', '2028-02-29'];
        yield 'datetime' => ['datetime', '2028-02-29T12:30', '2028-02-29 12:30:00'];
        yield 'select' => ['select', 'yes', 'yes'];
    }

    /**
     * Vsechny typy projdou jedinym definicnim validatorem se spravnym storage
     */
    #[DataProvider('defaults')]
    public function testEveryRegisteredTypeNormalizesItsDefault(string $code, string $default, string|int $expected): void
    {
        $input = ['name' => 'Field', 'kind' => $code, 'default_value' => $default, 'sort_order' => 0];
        if ($code === 'select') {
            $input['options'] = [['code' => 'yes', 'name' => 'Yes']];
        }
        $type = $this->types()->get($code);
        $definition = $this->validation()->field($input);
        self::assertSame($expected, $definition['values']['default_' . $type->storage()]);
    }

    /**
     * Projekce zachova presnou decimal hodnotu i integer koncici nulami
     */
    public function testEditorProjectionPreservesNumericMagnitude(): void
    {
        $registry = $this->types();
        self::assertSame('100', $registry->get('decimal')->editorValues(['default_decimal' => '100.00000000'])['default_value']);
        self::assertSame('100', $registry->get('decimal')->editorValues(['default_decimal' => '100'])['default_value']);
        self::assertSame(100, $registry->get('integer')->editorValues(['default_integer' => 100])['default_value']);
    }

    /**
     * Nepripusti tiche prepsani prvni definice pri kolizi kodu
     */
    public function testDuplicateTypeCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate field type code: text');
        new FieldTypeRegistry([new \Lemonade\Cms\ContentTypes\Field\Types\TextFieldType(), new \Lemonade\Cms\ContentTypes\Field\Types\TextFieldType()]);
    }
}
