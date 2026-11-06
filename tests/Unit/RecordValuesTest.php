<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Routing\AdminModuleRouteRegistrar;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\Record\RecordData;
use Lemonade\Cms\ContentTypes\Record\RecordEditorDefinition;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Cms\ContentTypes\Record\RecordValues;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Support\Slug\Slugger;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Overuje pouziti stejnych typovych pravidel pro obsah a definice
 */
final class RecordValuesTest extends DefinitionTestCase
{
    /**
     * Pokryva hranice povolenych hodnot i strukturovany nepovoleny vstup
     *
     * @return iterable<string,array{string,array<string,mixed>,mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'text short' => ['text', ['min_length' => '3'], 'ab'];
        yield 'textarea long' => ['textarea', ['max_length' => '3'], 'abcd'];
        yield 'richtext length' => ['richtext', ['max_length' => '3'], '<p>abcd</p>'];
        yield 'integer range' => ['integer', ['min_number' => '1'], '0'];
        yield 'integer fraction' => ['integer', [], '1.5'];
        yield 'decimal precision' => ['decimal', ['decimal_places' => '2'], '1.001'];
        yield 'decimal exact range' => ['decimal', ['decimal_places' => '8','min_number' => '1234567890123456.12345678'], '1234567890123456.12345677'];
        yield 'decimal float forbidden' => ['decimal', [], 1.25];
        yield 'boolean' => ['boolean', [], 'yes'];
        yield 'date range' => ['date', ['min_date' => '2028-02-01'], '2028-01-31'];
        yield 'invalid date' => ['date', [], '2027-02-29'];
        yield 'datetime range' => ['datetime', ['max_date' => '2028-02-01T12:00'], '2028-02-01T12:01'];
        yield 'select whitelist' => ['select', [], 'foreign'];
        yield 'array forbidden' => ['text', [], ['value']];
    }

    /**
     * @param array<string,mixed> $rules
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesRejectedEvenInDraft(string $kind, array $rules, mixed $value): void
    {
        $writer = $this->writer($kind, $rules);
        $this->expectException(DefinitionValidationException::class);
        $writer->prepare(12, 'cs', CmsPublicationState::Draft, new RecordData(null, ['field_8' => $value]));
    }

    public function testDraftCanRemainIncompleteButPublicationCannot(): void
    {
        $writer = $this->writer('text');
        self::assertTrue($writer->prepare(12, 'cs', CmsPublicationState::Draft, new RecordData(null, ['field_8' => ''])));
        $this->expectException(DefinitionValidationException::class);
        $writer->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, ['field_8' => '']));
    }

    public function testFalseAndZeroAreValidRequiredValues(): void
    {
        foreach (['integer','decimal','boolean'] as $kind) {
            self::assertTrue($this->writer($kind)->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, ['field_8' => '0'])));
        }
    }

    public function testDefaultAppliesOnlyToMissingValue(): void
    {
        $writer = $this->writer('text', ['default_value' => 'Fallback']);
        self::assertSame('Fallback', $writer->editorValues(12, 'cs')['field_8']);
        self::assertTrue($writer->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, [])));
        $this->expectException(DefinitionValidationException::class);
        $writer->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, ['field_8' => '']));
    }

    /**
     * Prazdne HTML zustava konceptem, ale nelze je publikovat
     */
    #[DataProvider('emptyRichtext')]
    public function testRequiredRichtextRejectsEmptyHtmlOnPublication(string $value): void
    {
        $writer = $this->writer('richtext');
        self::assertTrue($writer->prepare(12, 'cs', CmsPublicationState::Draft, new RecordData(null, ['field_8' => $value])));
        try {
            $writer->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, ['field_8' => $value]));
            self::fail('Empty richtext published');
        } catch (DefinitionValidationException $exception) {
            self::assertArrayHasKey('field_8', $exception->errors());
        }
    }

    /**
     * Vraci prazdne vystupy editoru vcetne HTML entit
     *
     * @return iterable<string,array{string}>
     */
    public static function emptyRichtext(): iterable
    {
        yield 'empty' => [''];
        yield 'paragraph' => ['<p></p>'];
        yield 'nested tags' => ['<p><strong></strong><br></p>'];
        yield 'whitespace' => [" \n\t "];
        yield 'nonbreaking space' => ['<p>&nbsp;</p>'];
        yield 'numeric space' => ['<div>&#160;</div>'];
    }

    /**
     * Vraci vsechna chybna pole najednou pod jejich transportnimi jmeny
     */
    public function testPublicationCollectsErrorsForEveryMissingField(): void
    {
        try {
            $this->writer('richtext', fieldCount: 2)->prepare(12, 'cs', CmsPublicationState::Published, new RecordData(null, []));
            self::fail('Missing required fields accepted');
        } catch (DefinitionValidationException $exception) {
            self::assertSame(['field_8', 'field_9'], array_keys($exception->errors()));
        }
    }

    /**
     * Povinnost klienta respektuje publikaci, opravneni a sdileni hodnot mezi jazyky
     */
    public function testEditorRequiredHintUsesPublicationContext(): void
    {
        $module = new RecordModule(1, 'Type', 'type', true);
        $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $modules->register($module);
        $router = new Router();
        (new AdminModuleRouteRegistrar($modules))->registerRoutes($router);
        foreach ([
            [1, true, false, 1, '0', 'publication_state'],
            [2, false, false, 1, '1', null],
            [1, false, false, 1, '0', null],
            [1, true, true, 0, '1', null],
            [1, true, true, 1, '0', 'publication_state'],
        ] as [$state, $canPublish, $otherPublished, $localized, $active, $condition]) {
            $definition = new RecordEditorDefinition(
                $module,
                $this->writer('richtext', localized: $localized),
                $this->types(),
                new UrlGenerator($router),
                new AdminModuleRouteResolver($modules),
            );
            $editor = $definition->create(
                ['id' => 12, 'translations' => [['publication_state' => $state]]],
                'cs',
                $canPublish,
                otherPublishedLocale: $otherPublished,
            );
            $section = $editor->tabs()[0]->blocks()[2];
            self::assertInstanceOf(SectionBlock::class, $section);
            $group = $section->blocks()[0];
            self::assertInstanceOf(FieldGroupBlock::class, $group);
            $field = $group->columns()[0]->field();
            self::assertInstanceOf(AdminEditorInputFieldDefinition::class, $field);
            self::assertTrue($field->isRequired());
            self::assertSame($active, $field->inputAttributes()['data-lemonade-required-active']);
            self::assertSame($condition, $field->inputAttributes()['data-lemonade-required-when'] ?? null);
            self::assertSame('content-types.validation.required', $field->inputAttributes()['data-lemonade-required-message']);
        }
    }

    /**
     * Ohranicuje SQL port; registry a vsechny validacni operace zustavaji skutecne
     *
     * @param array<string,mixed> $rules
     */
    private function writer(string $kind, array $rules = [], int $fieldCount = 1, int $localized = 1): RecordValues
    {
        $types = $this->types();
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);
        $container = new Container();
        $container->freeze();
        $validation = new FieldValidation(new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $container)), new Slugger(), $translator);
        $options = [['id' => 10,'code' => 'allowed','name' => 'Allowed','archived_at' => null]];
        $normalized = $types->get($kind)->validate($rules + ['default_value' => '','options' => $options], $validation);
        $field = ['id' => 8,'name' => 'Field','kind' => $kind,'required' => 1,'localized' => $localized,'archived_at' => null, 'code' => 'field', 'row_id' => 1, 'span' => 12] + $normalized['values'] + $types->emptyValues();
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $driver->method('escape')->willReturnArgument(0);
        $driver->method('query')->willReturnCallback(function (string $sql) use ($field, $options, $fieldCount): DatabaseResultInterface {
            $rows = [];
            if (str_contains($sql, 'FROM cms_content_field ')) {
                $rows = array_map(static fn(int $index): array => ['id' => 8 + $index] + $field, range(0, $fieldCount - 1));
            } elseif (str_contains($sql, 'FROM cms_content_field_option ')) {
                $rows = $options;
            } elseif (str_contains($sql, 'FROM cms_content_type ')) {
                $rows = [['id' => 1,'enabled' => 1]];
            }
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);
            $result->method('row_array')->willReturn($rows[0] ?? null);
            return $result;
        });
        $database = new Database($this->createMock(ConnectionInterface::class), $driver);
        return new RecordValues(new RecordModule(1, 'Type', 'type', true), new DefinitionModel($database), new RecordModel($database, $types), $types, $validation);
    }
}
