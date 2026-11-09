<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Overuje typovana pravidla vcetne hranic a neplatnych defaultu
 */
final class DefinitionValidationTest extends DefinitionTestCase
{
    /**
     * Normalizuje nezavisle prepinace a chybejici vstupy na vypnuto
     */
    public function testSystemMediaDefaultsAndIndependentValues(): void
    {
        $base = ['name' => 'Type', 'description' => 'Description'];
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$gallery, $attachments]) {
            $values = $this->validation()->type($base + [
                'gallery_enabled' => (string) $gallery,
                'attachments_enabled' => (string) $attachments,
            ]);
            self::assertSame($gallery, $values['gallery_enabled']);
            self::assertSame($attachments, $values['attachments_enabled']);
        }
        $defaults = $this->validation()->type($base);
        self::assertSame(0, $defaults['gallery_enabled']);
        self::assertSame(0, $defaults['attachments_enabled']);
    }

    public function testCodeIsSuggestedFromCzechName(): void
    {
        self::assertSame(['name' => 'Nabídky práce', 'code' => 'nabidky_prace'], $this->validation()->identity(['name' => 'Nabídky práce']));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'invalid class' => [['css_class' => 'valid bad@class']];
        yield 'numeric class' => [['css_class' => '123']];
        yield 'long class' => [['css_class' => str_repeat('a', 256)]];
        yield 'invalid HTML ID' => [['html_id' => 'two words']];
        yield 'numeric HTML ID' => [['html_id' => '1field']];
        yield 'long HTML ID' => [['html_id' => str_repeat('a', 129)]];
        yield 'empty name' => [['name' => ' ']];
        yield 'invalid code' => [['code' => 'bad-code']];
        yield 'long code' => [['code' => str_repeat('a', 65)]];
        yield 'unsupported kind' => [['kind' => 'gallery']];
        yield 'irrelevant rule' => [['min_number' => '1']];
        yield 'length range' => [['min_length' => '5', 'max_length' => '2']];
        yield 'short default' => [['min_length' => '5', 'default_value' => 'Hi']];
        yield 'fractional integer' => [['kind' => 'integer', 'default_value' => '1.5']];
        yield 'numeric range' => [['kind' => 'integer', 'min_number' => '2', 'max_number' => '1']];
        yield 'precision' => [['kind' => 'decimal', 'decimal_places' => '2', 'default_value' => '1.001']];
        yield 'too precise rule' => [['kind' => 'decimal', 'decimal_places' => '2', 'max_number' => '1.001']];
        yield 'large number' => [['kind' => 'integer', 'default_value' => '10000000000000000']];
        yield 'negative precision' => [['kind' => 'decimal', 'decimal_places' => '-1']];
        yield 'too high precision' => [['kind' => 'decimal', 'decimal_places' => '9']];
        yield 'fractional length' => [['min_length' => '2.5']];
        yield 'invalid date' => [['kind' => 'date', 'default_value' => '2026-02-30']];
        yield 'invalid time' => [['kind' => 'datetime', 'default_value' => '2026-02-01T25:30']];
        yield 'date range' => [['kind' => 'date', 'min_date' => '2026-03-01', 'max_date' => '2026-02-01']];
        yield 'date default outside range' => [['kind' => 'date', 'min_date' => '2026-03-01', 'default_value' => '2026-02-01']];
        yield 'invalid boolean' => [['kind' => 'boolean', 'default_value' => 'true']];
        yield 'structured name' => [['name' => ['bad']]];
        yield 'empty options' => [['kind' => 'select']];
        yield 'invalid options' => [['kind' => 'select', 'options' => [['name' => '', 'code' => 'a']]]];
        yield 'duplicate options' => [['kind' => 'select', 'options' => [['name' => 'A', 'code' => 'a'], ['name' => 'B', 'code' => 'a']]]];
        yield 'unknown default option' => [['kind' => 'select', 'options' => [['name' => 'A', 'code' => 'a']], 'default_value' => 'b']];
        yield 'options on text' => [['options' => [['name' => 'A', 'code' => 'a']]]];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionIsRejected(array $changes): void
    {
        $this->expectException(DefinitionValidationException::class);
        $this->validation()->field($changes + ['name' => 'Field', 'code' => 'field', 'kind' => 'text', 'sort_order' => 0]);
    }

    public function testTypedDefaultsPreserveZeroAndExactDecimals(): void
    {
        $base = ['name' => 'Field', 'sort_order' => 0];
        self::assertSame(0, $this->validation()->field($base + ['kind' => 'integer', 'default_value' => '0'])['values']['default_integer']);
        self::assertSame(0, $this->validation()->field($base + ['kind' => 'boolean', 'default_value' => '0'])['values']['default_boolean']);
        self::assertSame('9999999999999999.12345678', $this->validation()->field($base + ['kind' => 'decimal', 'decimal_places' => '8', 'min_number' => '9999999999999999.12345677', 'default_value' => '9999999999999999.12345678'])['values']['default_decimal']);
    }

    public function testSelectOptionsKeepOrderAndAllowedDefault(): void
    {
        $result = $this->validation()->field(['name' => 'Contract', 'kind' => 'select', 'sort_order' => 0, 'default_value' => 'part', 'options' => [['code' => 'full', 'name' => 'Full time'], ['code' => 'part', 'name' => 'Part time']]]);
        self::assertSame(['full', 'part'], array_column($result['options'], 'code'));
        self::assertSame('part', $result['values']['default_option']);
    }

    /**
     * Ulozi pouze vlastni prezentacni atributy bez zmeny identity a pravidel
     */
    public function testPresentationAttributesAreOptionalAndNormalized(): void
    {
        $input = ['name' => 'Místo výkonu práce', 'code' => 'workplace', 'kind' => 'text', 'sort_order' => 0, 'localized' => 0];
        $plain = $this->validation()->field($input)['values'];
        self::assertNull($plain['css_class']);
        self::assertNull($plain['html_id']);
        $custom = $this->validation()->field($input + ['css_class' => "  wide\t highlighted  ", 'html_id' => 'workplace-input'])['values'];
        self::assertSame('wide highlighted', $custom['css_class']);
        self::assertSame('workplace-input', $custom['html_id']);
        unset($plain['css_class'], $plain['html_id'], $custom['css_class'], $custom['html_id']);
        self::assertSame($plain, $custom);
    }

    /**
     * Odmitne kolizi vlastniho ID s automatickym ID jineho aktivniho pole
     */
    public function testCustomIdCannotShadowGeneratedId(): void
    {
        $definition = $this->presentationDefinition();
        $this->expectException(DefinitionValidationException::class);
        $this->validation()->prepare($definition, [], []);
    }

    /**
     * Archivovane pole nezabira ID; jeho obnoveni musi znovu projit kontrolou
     */
    public function testArchivedFieldDoesNotReservePresentationId(): void
    {
        $definition = $this->presentationDefinition();
        $definition['fields'][1]['archived'] = true;
        array_pop($definition['rows']);
        $prepared = $this->validation()->prepare($definition, [], []);
        self::assertCount(2, $prepared);
        self::assertNotNull($prepared[1]['values']['archived_at']);
    }

    /**
     * Sestavi dve pole s kolizi odvozeneho a explicitniho HTML ID
     *
     * @return array{fields:list<array<string,mixed>>,rows:list<array<string,mixed>>}
     */
    private function presentationDefinition(): array
    {
        return [
            'fields' => [
                ['key' => 'a', 'name' => 'A', 'code' => 'first', 'kind' => 'text'],
                ['key' => 'b', 'name' => 'B', 'code' => 'second', 'kind' => 'text', 'html_id' => 'field-first'],
            ],
            'rows' => [
                ['columns' => [['field' => 'a', 'span' => 12]]],
                ['columns' => [['field' => 'b', 'span' => 12]]],
            ],
        ];
    }
}
