<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Integration;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Audit\AuditLogWriterInterface;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\Content\CmsDomainMapping;
use Lemonade\Cms\Content\Models\CmsContentModel;
use Lemonade\Cms\ContentTypes\CmsContentTypesServiceProvider;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentFieldDefinitions;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentRecords;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentTypes;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordGridProjection;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\Record\RecordData;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Cms\ContentTypes\Record\RecordValues;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;

use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Support\Slug\Slugger;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Overuje realne migrace, typovane hodnoty a atomicitu CMS auditu v izolovane DB
 */
final class RecordLifecycleTest extends TestCase
{
    /**
     * Sestavi produkcni registry a validator pro nezavisle instance lifecycle
     *
     * @return array{FieldTypeRegistry,FieldValidation}
     */
    private function fieldServices(): array
    {
        $container = new Container();
        (new CmsContentTypesServiceProvider())->register($container);
        $container->freeze();
        $types = $container->get(FieldTypeRegistry::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);
        $validation = new FieldValidation(new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $container)), new Slugger(), $translator);
        return [$types, $validation];
    }

    public function testRecordsUseCmsLifecycleAndAtomicRelationalValues(): void
    {
        [$types, $validation] = $this->fieldServices();
        $databaseName = getenv('CONTENT_TYPES_INTEGRATION_DATABASE');
        if (!is_string($databaseName) || !str_starts_with($databaseName, 'content_types_test_')) {
            self::fail('Set CONTENT_TYPES_INTEGRATION_DATABASE to a dedicated content_types_test_ database.');
        }
        $config = DatabaseConfig::fromArray([
            'driver' => 'mysql', 'host' => 'db', 'database' => $databaseName,
            'username' => 'db', 'password' => 'db',
        ]);
        $connection = new MysqlConnection($config, new DatabaseInstrumentation());
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver($connection, $config, $escaper, new IdentifierProtector($escaper));
        $database = new Database($connection, $driver);
        $database->statement('CREATE TABLE IF NOT EXISTS system_user (id BIGINT UNSIGNED PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100), email VARCHAR(255), deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4');
        $database->statement('CREATE TABLE IF NOT EXISTS system_language (code VARCHAR(10) PRIMARY KEY, name VARCHAR(100) NOT NULL, enabled TINYINT NOT NULL, is_default TINYINT NOT NULL, sort_order INT NOT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4');
        $database->statement('CREATE TABLE IF NOT EXISTS system_audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_code VARCHAR(255), module_code VARCHAR(255), entity_type VARCHAR(255), entity_key VARCHAR(255), actor_type VARCHAR(30), actor_key VARCHAR(255), actor_user_id BIGINT UNSIGNED, payload TEXT, created_at DATETIME) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4');
        $database->statement('CREATE TABLE IF NOT EXISTS cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_cms_route_target (module_code,entity_id,locale), UNIQUE KEY uq_cms_route_locale_path (locale,path)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4');
        $database->statement('INSERT IGNORE INTO system_user (id) VALUES (7), (9)');
        $database->statement("INSERT IGNORE INTO system_language (code,name,enabled,is_default,sort_order) VALUES ('cs','Czech',1,1,1), ('en','English',1,0,2), ('de','German',1,0,3)");

        $schema = new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver);
        foreach ([new CreateContentTypes(), new CreateContentFieldDefinitions(), new CreateContentRecords()] as $migration) {
            $migration->up($schema);
        }
        $definitions = new DefinitionModel($database);
        $typeId = $definitions->createType(['code' => 'integration', 'name' => 'Integration', 'enabled' => 1]);
        $secondType = $definitions->createType(['code' => 'other', 'name' => 'Other', 'enabled' => 1]);
        $rowId = $definitions->saveRow($typeId, null, 0);
        $ids = [];
        foreach ($types->codes() as $index => $kind) {
            $type = $types->get($kind);
            $input = ['name' => $kind,'code' => $kind,'kind' => $kind,'decimal_places' => '8','default_value' => '','options' => [['code' => 'first','name' => 'First'], ['code' => 'second','name' => 'Second']]];
            $validated = $type->validate($input, $validation);
            $ids[$kind] = $definitions->saveField($typeId, null, ['name' => $kind,'code' => $kind,'kind' => $kind,'row_id' => $rowId,'span' => 12,'required' => 1,'localized' => $kind === 'integer' ? 0 : 1,'sort_order' => $index] + $validated['values']);
            $definitions->saveOptions($ids[$kind], $validated['options']);
        }
        $module = new RecordModule($typeId, 'Integration', 'integration', true);
        $other = new RecordModule($secondType, 'Other', 'other', true);
        $lifecycle = $this->lifecycle($database, $module, new AuditLogService($database));
        $id = $lifecycle->createDraft(7, 'cs');
        $values = new RecordValues($module, $definitions, new RecordModel($database, $types), $types, $validation);
        // Nevyplneny koncept lze ulozit, primy pokus o publikaci ale nic nezapise.
        $draft = ['locale' => 'cs', 'title' => 'Incomplete draft', 'publication_state' => '1'];
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($draft), RecordData::fromEditor($draft));
        $beforePublication = $this->snapshot($database);
        try {
            $publish = ['title' => 'Must not persist', 'publication_state' => '2', 'author_name' => 'Must not persist'] + $draft;
            $lifecycle->save(7, $id, CmsContentInput::fromEditor($publish), RecordData::fromEditor($publish));
            self::fail('Incomplete draft published');
        } catch (DefinitionValidationException $exception) {
            self::assertCount(count($ids), $exception->errors());
            self::assertArrayHasKey('field_' . $ids['richtext'], $exception->errors());
        }
        self::assertSame($beforePublication, $this->snapshot($database));
        $payload = ['locale' => 'cs','title' => 'Integration record','publication_state' => '2','page_title' => 'SEO','meta_description' => 'Description'];
        foreach (['text' => 'Text', 'textarea' => "First\nSecond", 'richtext' => '<p>Rich <strong>text</strong></p>', 'integer' => '0', 'decimal' => '1234567890123456.12345678', 'boolean' => '0','date' => '2028-02-29','datetime' => '2028-02-29T12:34','select' => 'first'] as $kind => $value) {
            $payload['field_' . $ids[$kind]] = $value;
        }
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($payload), RecordData::fromEditor($payload));
        $loaded = $values->editorValues($id, 'cs');
        foreach ($ids as $fieldId) {
            self::assertSame($payload['field_' . $fieldId], (string) $loaded['field_' . $fieldId]);
        }
        $auditCount = $database->table('system_audit_log')->countAllResults();
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($payload), RecordData::fromEditor($payload));
        self::assertSame($auditCount, $database->table('system_audit_log')->countAllResults(), 'No-op must not audit');
        foreach (['text' => '', 'richtext' => '<p>&nbsp;</p>', 'integer' => '1.5', 'decimal' => '1.123456789', 'boolean' => 'yes','date' => '2027-02-29','datetime' => '2028-02-30T12:34','select' => 'foreign'] as $kind => $invalid) {
            $bad = $payload;
            unset($bad['publication_state']); // Already published still enforces required values.
            $bad['field_' . $ids[$kind]] = $invalid;
            $bad['title'] = 'Must not persist';
            $bad['author_name'] = 'Must not persist';
            $beforeInvalid = $this->snapshot($database);
            try {
                $lifecycle->save(7, $id, CmsContentInput::fromEditor($bad), RecordData::fromEditor($bad));
                self::fail('Invalid value accepted: ' . $kind);
            } catch (DefinitionValidationException $exception) {
                self::assertArrayHasKey('field_' . $ids[$kind], $exception->errors());
                self::assertSame($beforeInvalid, $this->snapshot($database));
                self::assertSame($auditCount, $database->table('system_audit_log')->countAllResults());
            }
        }
        $english = ['locale' => 'en','title' => 'English draft', 'field_' . $ids['integer'] => ''];
        try {
            $lifecycle->save(7, $id, CmsContentInput::fromEditor($english), RecordData::fromEditor($english));
            self::fail('Published global required value lost');
        } catch (DefinitionValidationException) {
            self::assertNull((new RecordModel($database, $types))->translationId($id, 'en'));
        }
        $english['field_' . $ids['integer']] = '4';
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($english), RecordData::fromEditor($english));
        self::assertSame('4', (string) $values->editorValues($id, 'cs')['field_' . $ids['integer']]);
        self::assertSame('Text', $values->editorValues($id, 'cs')['field_' . $ids['text']]);
        self::assertSame('', $values->editorValues($id, 'en')['field_' . $ids['text']]);
        $otherRead = new CmsContentReadModel($database, $other->contentDefinition());
        self::assertNull($otherRead->findForEditor($id));
        self::assertNull($otherRead->translation($id, 'cs'));
        self::assertNull($otherRead->ownerId($id));
        try {
            $this->lifecycle($database, $other, new AuditLogService($database))->delete(7, $id);
            self::fail('Foreign type deletion accepted');
        } catch (\DomainException) {
            self::assertNull($this->row($database->table('cms_content_record')->where('id', $id)->first())['deleted_at']);
        }
        $grid = new CmsContentDataGrid($database, $module->contentDefinition());
        $projection = new RecordGridProjection($definitions->fields($typeId), $types);
        $query = new DataGridQuery(1, 25, 'field_' . $ids['decimal'], 'desc', '', ['field_' . $ids['select'] => 'first']);
        self::assertSame(1, $grid->listForDataGrid($query, 'cs', 7, projection: $projection)->total());
        self::assertSame(0, $grid->listForDataGrid($query, 'cs', 9, projection: $projection)->total());

        $numericQuery = new DataGridQuery(1, 25, 'field_' . $ids['decimal'], 'asc', '', ['field_' . $ids['decimal'] => '1234567890123456.12345678']);
        self::assertSame(1, $grid->listForDataGrid($numericQuery, 'cs', projection: $projection)->total());
        $dateQuery = new DataGridQuery(1, 25, 'title', 'asc', '', ['field_' . $ids['date'] => '2028-02-28']);
        self::assertSame(0, $grid->listForDataGrid($dateQuery, 'cs', projection: $projection)->total());
        $translationId = (new RecordModel($database, $types))->translationId($id, 'cs');
        foreach ([
            ['record_id' => $id,'type_id' => $typeId,'field_id' => $ids['text'],'translation_id' => $translationId,'value_text' => 'Duplicate'],
            ['record_id' => $id,'type_id' => $secondType,'field_id' => $ids['text'],'translation_id' => $translationId,'value_text' => 'Wrong type'],
            ['record_id' => $id,'type_id' => $typeId,'field_id' => $ids['integer'],'translation_id' => $translationId,'value_option' => $definitions->options($ids['select'])[0]['id']],
        ] as $invalidRow) {
            try {
                $database->table('cms_content_record_translation_value')->insert($invalidRow);
                self::fail('Invalid relational value accepted');
            } catch (\Lemonade\Framework\Database\Exception\DatabaseException $exception) {
                self::assertStringContainsString('Database query failed', $exception->getMessage());
            }
        }
        $lifecycle->delete(7, $id);
        self::assertSame(0, $grid->listForDataGrid($query, 'cs', projection: $projection)->total());
        $lifecycle->restore(7, $id);
        self::assertSame('1', (string) $this->row($database->table('cms_content_record_translation')->where('record_id', $id)->where('locale', 'cs')->first())['publication_state']);
        $failure = new class implements AuditLogWriterInterface {
            public function record(DomainEvent $event, AuditActor $actor): void
            {
                throw new RuntimeException('Audit failure');
            }
        };
        $before = $values->editorValues($id, 'cs');
        $bad = $payload;
        $bad['title'] = 'Should rollback';
        $bad['field_' . $ids['decimal']] = '2.01';
        $bad['field_' . $ids['integer']] = '8';
        $auditCount = $database->table('system_audit_log')->countAllResults();
        try {
            $this->lifecycle($database, $module, $failure)->save(7, $id, CmsContentInput::fromEditor($bad), RecordData::fromEditor($bad));
            self::fail('Audit failure accepted');
        } catch (RuntimeException $exception) {
            self::assertSame('Audit failure', $exception->getMessage());
        }
        self::assertSame($before, $values->editorValues($id, 'cs'));
        self::assertSame('Integration record', $this->row($database->table('cms_content_record_translation')->where('record_id', $id)->where('locale', 'cs')->first())['title']);
        self::assertSame($auditCount, $database->table('system_audit_log')->countAllResults());
        $definitions->updateType($typeId, ['enabled' => 0], 1);
        try {
            $lifecycle->createDraft(7, 'cs');
            self::fail('Disabled type allowed creation');
        } catch (\DomainException $exception) {
            self::assertSame('records.validation.type_disabled', $exception->getMessage());
        }
        self::assertSame(1, $database->table('cms_content_record')->countAllResults());
        self::assertNotNull((new CmsContentReadModel($database, $module->contentDefinition()))->findForEditor($id));

        $this->lifecycle($database, $other, new AuditLogService($database))->createDraft(7, 'de');
        $database->table('system_language')->where('code', 'de')->set(['enabled' => 0])->update();
        $ownRead = new CmsContentReadModel($database, $module->contentDefinition());
        self::assertNotContains('de', array_column($ownRead->dataGridLocales(), 'code'));
        self::assertContains('de', array_column($otherRead->dataGridLocales(), 'code'));
        self::assertSame(1, $grid->listForDataGrid(new DataGridQuery(1, 25, 'title', 'asc', '', []), 'cs')->total());
    }

    /**
     * Zachyti systemova data, hodnoty, routy i audit pro overeni atomicity
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function snapshot(Database $database): array
    {
        $snapshot = [];
        foreach (['cms_content_record', 'cms_content_record_translation', 'cms_content_record_value', 'cms_content_record_translation_value', 'cms_route', 'system_audit_log'] as $table) {
            $snapshot[$table] = $database->table($table)->getArray();
        }
        return $snapshot;
    }

    /**
     * Vrati existujici radek po explicitni testove kontrole
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function row(?array $row): array
    {
        self::assertNotNull($row);
        return $row;
    }

    /**
     * Pripojuje skutecne CMS sluzby ke spolecne databazi
     *
     * @return CmsContentLifecycle<RecordData>
     */
    private function lifecycle(Database $database, RecordModule $module, AuditLogWriterInterface $writer): CmsContentLifecycle
    {
        [$types, $validation] = $this->fieldServices();
        $definition = $module->contentDefinition();
        $events = new TransactionalEventProcessor($database, $writer, new DomainEventDispatcher(new NullLogger()));
        $languages = new LanguageRegistry($database, new CacheManager(new ArrayCacheItemPool()));
        $runtime = new CmsAdminRuntime($events, $languages, new LocalUserDirectory($database));
        $locales = new class implements PublicLocaleRegistryInterface {
            public function snapshot(): PublicLocaleSnapshot
            {
                return new PublicLocaleSnapshot('cs', ['cs', 'en', 'de'], ['cs', 'en', 'de']);
            }
        };
        $guard = new CmsReservedPathGuard(new Router(), new ServerRequest('GET', '/'), $locales);
        $routes = new CmsRouteReservationService(new CmsRouteRepository($database), new CmsRouteCandidateResolver(), $guard);

        return new CmsContentLifecycle($definition, new CmsContentModel($database, $definition), new CmsDomainMapping(RecordData::class), $routes, new Slugger(), $runtime, new RecordValues($module, new DefinitionModel($database), new RecordModel($database, $types), $types, $validation));
    }
}
