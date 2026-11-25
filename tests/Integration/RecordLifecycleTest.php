<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Integration;

use DomainException;
use InvalidArgumentException;
use Lemonade\Admin\Action\ModuleActionDefinition;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Audit\AuditLogWriterInterface;
use Lemonade\Admin\Auth\AdminPrincipalInterface;
use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Auth\LocalAdminPrincipal;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Editor\AdminEditor\Field\SelectField;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorFieldRenderer;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderer;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Admin\File\PublishedMediaInterface;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Platform\Migrations\CreateCoreFiles;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Admin\Routing\AdminModuleRouteRegistrar;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\Content\CmsDomainMapping;
use Lemonade\Cms\Content\Models\CmsContentModel;
use Lemonade\Cms\ContentTypes\CmsContentTypesServiceProvider;
use Lemonade\Cms\ContentTypes\DefinitionService;
use Lemonade\Cms\ContentTypes\DefinitionValidation;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Cms\ContentTypes\Migrations\AddContentRecordRelations;
use Lemonade\Cms\ContentTypes\Migrations\AddContentRepeaters;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeSortOrder;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeSystemMedia;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeTagsAndRoutePrefixes;
use Lemonade\Cms\ContentTypes\Migrations\AddContentTypeThumbnail;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentFieldDefinitions;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentRecords;
use Lemonade\Cms\ContentTypes\Migrations\CreateContentTypes;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Models\RecordGridProjection;
use Lemonade\Cms\ContentTypes\Models\RecordModel;
use Lemonade\Cms\ContentTypes\Public\PublishedRecordReadModel;
use Lemonade\Cms\ContentTypes\Record\RecordData;
use Lemonade\Cms\ContentTypes\Record\RecordDataGrid;
use Lemonade\Cms\ContentTypes\Record\RecordEditorDefinition;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Cms\ContentTypes\Record\RecordValues;
use Lemonade\Cms\ContentTypes\Record\RelationOptionsController;
use Lemonade\Cms\ContentTypes\Record\RelationOptionsRouteRegistrar;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Core\Http\ResponseBuilder;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Exception\DatabaseException;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseMeasurements;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use Lemonade\Framework\Http\Response\Responses;
use Lemonade\Framework\Image\Value\ImageFormat;
use Lemonade\Framework\Localization\Config\LocalizationConfig;
use Lemonade\Framework\Localization\Config\LocalizationUrlConfig;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Security\Csrf\CsrfTokenManager;
use Lemonade\Framework\Security\Csrf\CsrfViewHelper;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewHelpers;
use Lemonade\Framework\View\ViewResourceRegistry;
use Lemonade\Image\ImageOriginalMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

final class RecordLifecycleTest extends TestCase
{
    /**
     * @return array{FieldTypeRegistry,FieldValidation}
     */
    private function fieldServices(): array
    {
        $container = new Container();
        (new CmsContentTypesServiceProvider())->register($container);
        $container->freeze();
        $types = $container->get(FieldTypeRegistry::class);
        $translator = self::createStub(TranslatorInterface::class);
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
            'driver' => 'mysql', 'host' => getenv('CONTENT_TYPES_INTEGRATION_HOST') !== false ? getenv('CONTENT_TYPES_INTEGRATION_HOST') : 'db', 'database' => $databaseName,
            'username' => 'db', 'password' => 'db',
        ]);
        $instrumentation = new DatabaseInstrumentation();
        $connection = new MysqlConnection($config, $instrumentation);
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
        foreach ([new CreateCoreFiles(), new CreateContentTypes(), new CreateContentFieldDefinitions(), new CreateContentRecords(), new AddContentTypeSystemMedia(), new AddContentTypeThumbnail(), new AddContentTypeTagsAndRoutePrefixes(), new AddContentRecordRelations(), new AddContentRepeaters()] as $migration) {
            $migration->up($schema);
        }
        $database->table('cms_content_type')->insert(['code' => 'legacy_first', 'name' => 'Legacy first']);
        $legacyFirstId = (int) $database->lastInsertId();
        $database->table('cms_content_type')->insert(['code' => 'legacy_second', 'name' => 'Legacy second']);
        $legacySecondId = (int) $database->lastInsertId();
        $preMigrationModules = (new RecordModel($database, $types))->modules();
        self::assertSame(
            [$legacyFirstId, $legacySecondId],
            array_map(static fn(RecordModule $module): int => $module->id(), $preMigrationModules),
        );
        (new AddContentTypeSortOrder($database))->up($schema);
        $legacyTypes = $database->table('cms_content_type')->orderBy('sort_order')->orderBy('id')->getArray();
        self::assertSame([$legacyFirstId, $legacySecondId], array_map(static fn(array $type): int => (int) $type['id'], $legacyTypes));
        self::assertSame([0, 1], array_map(static fn(array $type): int => (int) $type['sort_order'], $legacyTypes));
        $database->table('cms_content_type')->whereIn('id', [$legacyFirstId, $legacySecondId])->delete();
        $bodyColumns = $database->select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content_record_translation' AND COLUMN_NAME IN ('summary', 'content')",
        );
        self::assertSame([], $bodyColumns);
        $definitions = new DefinitionModel($database);
        $typeId = $definitions->createType(['code' => 'integration', 'name' => 'Integration', 'enabled' => 1]);
        $secondType = $definitions->createType(['code' => 'other', 'name' => 'Other', 'enabled' => 1]);
        self::assertSame(0, (int) $this->row($definitions->type($typeId))['sort_order']);
        self::assertSame(1, (int) $this->row($definitions->type($secondType))['sort_order']);
        $definitions->updateType($typeId, ['sort_order' => 5], (int) $this->row($definitions->type($typeId))['revision']);
        $orderedModules = (new RecordModel($database, $types))->modules();
        self::assertSame([$secondType, $typeId], array_map(static fn(RecordModule $module): int => $module->id(), $orderedModules));
        $definitions->updateType($secondType, ['sort_order' => 5], (int) $this->row($definitions->type($secondType))['revision']);
        $tiedModules = (new RecordModel($database, $types))->modules();
        self::assertSame([$typeId, $secondType], array_map(static fn(RecordModule $module): int => $module->id(), $tiedModules));
        $definitions->updateType($secondType, ['enabled' => 0], (int) $this->row($definitions->type($secondType))['revision']);
        $disabledModules = (new RecordModel($database, $types))->modules();
        self::assertFalse($disabledModules[1]->enabled());
        $definitions->updateType($typeId, ['sort_order' => 0], (int) $this->row($definitions->type($typeId))['revision']);
        $definitions->updateType($secondType, ['sort_order' => 1, 'enabled' => 1], (int) $this->row($definitions->type($secondType))['revision']);
        self::assertSame(0, (int) $this->row($database->table('cms_content_type')->where('id', $typeId)->first())['thumbnail_enabled']);
        $rowId = $definitions->saveRow($typeId, null, 0);
        $ids = [];
        foreach ($types->codes() as $index => $kind) {
            if ($kind === 'relation') {
                continue;
            }
            $type = $types->get($kind);
            if ($type->storage() === null) {
                continue;
            }
            $input = ['name' => $kind,'code' => $kind,'kind' => $kind,'decimal_places' => '8','default_value' => '','options' => [['code' => 'first','name' => 'First'], ['code' => 'second','name' => 'Second']]];
            $validated = $type->validate($input, $validation);
            $ids[$kind] = $definitions->saveField($typeId, null, ['name' => $kind,'code' => $kind,'kind' => $kind,'row_id' => $rowId,'span' => 12,'required' => 1,'localized' => $kind === 'integer' ? 0 : 1,'sort_order' => $index] + $validated['values']);
            $definitions->saveOptions($ids[$kind], $validated['options']);
        }
        $module = new RecordModule($typeId, 'Integration', 'integration', true);
        $other = new RecordModule($secondType, 'Other', 'other', true);
        $lifecycle = $this->lifecycle($database, $module, new AuditLogService($database));
        $id = $lifecycle->createDraft(7, 'cs');
        $relationFieldId = $definitions->saveField($typeId, null, [
            'name' => 'Related', 'code' => 'related', 'kind' => 'relation', 'row_id' => $rowId,
            'required' => 0, 'localized' => 0, 'sort_order' => 100,
            'relation_target_type_id' => $secondType, 'relation_multiple' => 1,
        ]);
        $targetId = $this->lifecycle($database, $other, new AuditLogService($database))->createDraft(7, 'cs');
        $link = [
            'source_record_id' => $id, 'source_type_id' => $typeId, 'field_id' => $relationFieldId,
            'target_record_id' => $targetId, 'target_type_id' => $secondType, 'sort_order' => 0,
        ];
        $database->table('cms_content_record_relation')->insert($link);
        self::assertSame($link['target_record_id'], (int) $this->row($database->table('cms_content_record_relation')->where('field_id', $relationFieldId)->first())['target_record_id']);
        foreach ([$link, ['target_record_id' => $id, 'target_type_id' => $typeId] + array_diff_key($link, ['target_record_id' => true, 'target_type_id' => true]), ['source_type_id' => $secondType] + $link] as $invalidLink) {
            try {
                $database->table('cms_content_record_relation')->insert($invalidLink);
                self::fail('Invalid relation accepted by database.');
            } catch (DatabaseException) {
                // Uniqueness and composite foreign keys protect the stored relation.
            }
        }
        $values = new RecordValues($module, $definitions, new RecordModel($database, $types), $types, $validation, new AuthorizationService(self::createStub(CurrentPrincipalProviderInterface::class), $database));
        $draft = ['locale' => 'cs', 'title' => '', 'publication_state' => '1'];
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($draft), RecordData::fromEditor($draft));
        $beforePublication = $this->snapshot($database);
        self::assertNull($database->table('cms_route')->where('entity_id', $id)->first());
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($draft), RecordData::fromEditor($draft));
        self::assertSame($beforePublication, $this->snapshot($database));
        try {
            $publish = ['publication_state' => '2'] + $draft;
            $lifecycle->save(7, $id, CmsContentInput::fromEditor($publish), RecordData::fromEditor($publish));
            self::fail('Untitled record published');
        } catch (CmsContentValidationException $exception) {
            self::assertArrayHasKey('title', $exception->getErrors());
        }
        self::assertSame($beforePublication, $this->snapshot($database));
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
            self::assertIsScalar($loaded['field_' . $fieldId]);
            self::assertSame($payload['field_' . $fieldId], (string) $loaded['field_' . $fieldId]);
        }
        $auditCount = $database->table('system_audit_log')->countAllResults();
        $lifecycle->save(7, $id, CmsContentInput::fromEditor($payload), RecordData::fromEditor($payload));
        self::assertSame($auditCount, $database->table('system_audit_log')->countAllResults(), 'No-op must not audit');
        foreach (['text' => '', 'richtext' => '<p>&nbsp;</p>', 'integer' => '1.5', 'decimal' => '1.123456789', 'boolean' => 'yes','date' => '2027-02-29','datetime' => '2028-02-30T12:34','select' => 'foreign'] as $kind => $invalid) {
            $bad = $payload;
            unset($bad['publication_state']);
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
        $integerValue = $values->editorValues($id, 'cs')['field_' . $ids['integer']];
        self::assertIsScalar($integerValue);
        self::assertSame('4', (string) $integerValue);
        self::assertSame('Text', $values->editorValues($id, 'cs')['field_' . $ids['text']]);
        self::assertSame('', $values->editorValues($id, 'en')['field_' . $ids['text']]);
        $otherRead = new CmsContentReadModel($database, $other->contentDefinition());
        self::assertNull($otherRead->findForEditor($id));
        self::assertNull($otherRead->translation($id, 'cs'));
        self::assertNull($otherRead->ownerId($id));
        try {
            $this->lifecycle($database, $other, new AuditLogService($database))->delete(7, $id);
            self::fail('Foreign type deletion accepted');
        } catch (DomainException) {
            self::assertNull($this->row($database->table('cms_content_record')->where('id', $id)->first())['deleted_at']);
        }
        $grid = new CmsContentDataGrid($database, $module->contentDefinition());
        $projection = new RecordGridProjection($definitions->fields($typeId), $types, new RecordModel($database, $types));
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
            } catch (DatabaseException $exception) {
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
        $files = new AdminFileModel($driver, new FileReadModel($driver));
        foreach (['image', 'gallery', 'file', 'files'] as $kind) {
            $fieldId = $definitions->saveField($typeId, null, [
                'name' => $kind, 'code' => $kind === 'image' ? 'thumbnail' : 'media_' . $kind, 'kind' => $kind, 'row_id' => $rowId,
                'span' => 6, 'required' => 0, 'localized' => 0, 'sort_order' => 100,
            ]);
            $field = array_column($definitions->fields($typeId), null, 'id')[$fieldId];
            $usage = $types->mediaDefinition($module->code(), $field);
            self::assertNotNull($usage);
            $fileId = $files->createIdentity($module->code(), $id, $usage->usage(), $usage->kind());
            if ($kind === 'image') {
                $files->updateImageSource($fileId, 'custom-asset', 'custom-version', new ImageOriginalMetadata('custom.png', ImageFormat::Png, 10, 1, 1));
            }
            $freshValues = new RecordValues($module, $definitions, new RecordModel($database, $types), $types, $validation, new AuthorizationService(self::createStub(CurrentPrincipalProviderInterface::class), $database));
            self::assertArrayNotHasKey('field_' . $fieldId, $freshValues->editorValues($id, 'cs'));
            self::assertNotContains($fieldId, array_column($freshValues->scalarFields(), 'id'));
            foreach (['2026-11-09 00:00:00', null] as $archivedAt) {
                $definitions->saveField($typeId, $fieldId, ['name' => 'Renamed', 'span' => 12, 'sort_order' => 101, 'archived_at' => $archivedAt]);
                $reloaded = array_column($definitions->fields($typeId), null, 'id')[$fieldId];
                self::assertSame($archivedAt === null, $types->mediaDefinition($module->code(), $reloaded) !== null);
                self::assertSame([$fileId], array_column($files->listForEntity($module->code(), $id, $usage->usage()), 'id'));
            }
            self::assertSame(0, $database->table('cms_content_record_value')->where('field_id', $fieldId)->countAllResults());
            self::assertSame(0, $database->table('cms_content_record_translation_value')->where('field_id', $fieldId)->countAllResults());
        }
        $thumbnailId = $files->createIdentity($module->code(), $id, 'thumbnail', 'image');
        $files->updateImageSource($thumbnailId, 'first-asset', 'first-version', new ImageOriginalMetadata('first.png', ImageFormat::Png, 10, 1, 1));
        $files->updateImageSource($thumbnailId, 'second-asset', 'second-version', new ImageOriginalMetadata('second.png', ImageFormat::Png, 12, 2, 2));
        self::assertSame([$thumbnailId], array_column($files->listForEntity($module->code(), $id, 'thumbnail'), 'id'));
        self::assertSame('second-version', $files->listForEntity($module->code(), $id, 'thumbnail')[0]['source_version']);
        $galleryIds = [
            $files->createIdentity($module->code(), $id, 'gallery', 'image'),
            $files->createIdentity($module->code(), $id, 'gallery', 'image'),
        ];
        $files->updateImageSource($galleryIds[0], 'gallery-first', 'first-version', new ImageOriginalMetadata('first.png', ImageFormat::Png, 10, 1, 1));
        $files->updateImageSource($galleryIds[1], 'gallery-second', 'second-version', new ImageOriginalMetadata('second.png', ImageFormat::Png, 10, 1, 1));
        $attachmentIds = [
            $files->createFile($module->code(), $id, 'attachment', 'one.pdf', 'pdf', 'application/pdf', 10, 'test/one.pdf'),
            $files->createFile($module->code(), $id, 'attachment', 'two.pdf', 'pdf', 'application/pdf', 20, 'test/two.pdf'),
        ];
        $files->reorder($module->code(), $id, 'attachment', array_reverse($attachmentIds));
        self::assertSame(array_reverse($attachmentIds), array_column($files->listForEntity($module->code(), $id, 'attachment'), 'id'));
        foreach ([[false, false, false], [true, false, false], [false, true, false], [false, false, true], [true, true, false], [true, false, true], [false, true, true], [true, true, true], [false, false, false], [true, true, true]] as $position => [$thumbnailEnabled, $galleryEnabled, $attachmentsEnabled]) {
            $definitions->updateType($typeId, [
                'thumbnail_enabled' => (int) $thumbnailEnabled,
                'gallery_enabled' => (int) $galleryEnabled,
                'attachments_enabled' => (int) $attachmentsEnabled,
            ], $position + 1);
            $currentModule = (new RecordModel($database, $types))->modules()[0];
            $usages = new AdminFileUsageRegistry();
            $usages->registerCapabilities($currentModule->code(), $currentModule->fileCapabilities());
            foreach (['thumbnail' => $thumbnailEnabled, 'gallery' => $galleryEnabled, 'attachment' => $attachmentsEnabled] as $usage => $allowed) {
                try {
                    $usages->require($currentModule->code(), $usage);
                    self::assertTrue($allowed);
                } catch (OutOfBoundsException) {
                    self::assertFalse($allowed);
                }
            }
            self::assertSame([$thumbnailId], array_column($files->listForEntity($module->code(), $id, 'thumbnail'), 'id'));
            self::assertSame($galleryIds, array_column($files->listForEntity($module->code(), $id, 'gallery'), 'id'));
            self::assertSame(array_reverse($attachmentIds), array_column($files->listForEntity($module->code(), $id, 'attachment'), 'id'));
        }
        $files->remove($attachmentIds[0]);
        $files->remove($thumbnailId);
        self::assertSame([], $files->listForEntity($module->code(), $id, 'thumbnail'));
        self::assertSame([$attachmentIds[1]], array_column($files->listForEntity($module->code(), $id, 'attachment'), 'id'));
        self::assertSame($galleryIds, array_column($files->listForEntity($module->code(), $id, 'gallery'), 'id'));
        $database->statement('CREATE TABLE system_role (id BIGINT UNSIGNED PRIMARY KEY, deleted_at DATETIME NULL, is_super_admin TINYINT NOT NULL)');
        $database->statement('CREATE TABLE system_user_role (user_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL)');
        $database->statement('INSERT INTO system_role (id, is_super_admin) VALUES (1, 1)');
        $database->statement('INSERT INTO system_user_role (user_id, role_id) VALUES (7, 1)');
        $principals = new class implements CurrentPrincipalProviderInterface {
            public function currentPrincipal(): ?AdminPrincipalInterface
            {
                return null;
            }

            public function currentUser(): AuthenticatedUser
            {
                return new AuthenticatedUser(7, 'admin@example.test');
            }
        };
        $allowTargets = new AuthorizationService($principals, $database);
        $relationLifecycle = $this->lifecycle($database, $module, new AuditLogService($database), $allowTargets);
        $target2 = $this->lifecycle($database, $other, new AuditLogService($database))->createDraft(7, 'cs');
        $targetTitle = ['locale' => 'cs', 'title' => 'Cílový záznam'];
        $this->lifecycle($database, $other, new AuditLogService($database))->save(7, $target2, CmsContentInput::fromEditor($targetTitle), RecordData::fromEditor($targetTitle));
        $singleFieldId = $definitions->saveField($typeId, null, [
            'name' => 'Primary', 'code' => 'primary', 'kind' => 'relation', 'row_id' => $rowId,
            'required' => 1, 'localized' => 0, 'sort_order' => 101, 'css_class' => 'relation-picker',
            'relation_target_type_id' => $secondType, 'relation_multiple' => 0,
        ]);
        $baseRelationInput = ['locale' => 'cs', 'title' => 'Integration record', 'publication_state' => '2'];
        try {
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($baseRelationInput), RecordData::fromEditor($baseRelationInput));
            self::fail('Published record accepted without its required relation.');
        } catch (DefinitionValidationException $exception) {
            self::assertArrayHasKey('field_' . $singleFieldId, $exception->errors());
        }
        $relations = [
            'field_' . $singleFieldId . '_present' => '1', 'field_' . $singleFieldId => (string) $targetId,
            'field_' . $relationFieldId . '_present' => '1', 'field_' . $relationFieldId => [(string) $target2, (string) $targetId],
        ] + $baseRelationInput;
        $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($relations), RecordData::fromEditor($relations));
        $publishedTarget = ['locale' => 'cs', 'title' => 'Cílový záznam', 'publication_state' => '2'];
        $this->lifecycle($database, $other, new AuditLogService($database))->save(
            7,
            $target2,
            CmsContentInput::fromEditor($publishedTarget),
            RecordData::fromEditor($publishedTarget),
        );
        $recordModel = new RecordModel($database, $types);
        self::assertSame([$targetId], $this->relationIds($recordModel, $id, $singleFieldId));
        self::assertSame([$target2, $targetId], $this->relationIds($recordModel, $id, $relationFieldId));
        self::assertSame([$target2, $targetId], array_map(
            static fn(array $row): int => (int) $row['id'],
            $recordModel->relationRowsForSources([$id], [$relationFieldId], 'cs'),
        ));
        $batchRows = $recordModel->relationRowsForSources([$id], [$relationFieldId, $singleFieldId], 'en');
        self::assertCount(3, $batchRows);
        self::assertSame($target2, (int) $batchRows[0]['id']);
        self::assertSame($id, (int) $batchRows[0]['source_record_id']);
        $relationProjection = new RecordGridProjection($definitions->fields($typeId), $types, $recordModel);
        $relationQuery = new DataGridQuery(1, 1, 'title', 'asc', '', [
            'field_' . $relationFieldId => (string) $target2,
            'field_' . $ids['select'] => 'first',
            'state' => 'published',
            'locale' => 'cs',
        ]);
        $relationPage = $grid->listForDataGrid($relationQuery, 'cs', projection: $relationProjection);
        self::assertSame(1, $relationPage->total());
        self::assertSame([$id], array_map(static fn(array $row): int => (int) $row['id'], $relationPage->items()));
        $nextRelationPage = new DataGridQuery(2, 1, 'title', 'asc', '', $relationQuery->filters());
        self::assertSame([], $grid->listForDataGrid($nextRelationPage, 'cs', projection: $relationProjection)->items());
        $noMatch = new DataGridQuery(1, 25, 'title', 'asc', '', [
            'field_' . $relationFieldId => (string) $target2,
            'field_' . $ids['select'] => 'second',
        ]);
        self::assertSame(0, $grid->listForDataGrid($noMatch, 'cs', projection: $relationProjection)->total());
        $enabledModules = self::createStub(PublicModuleStateResolverInterface::class);
        $enabledModules->method('isDiscoveredInstalledAndEnabled')->willReturn(true);
        $public = new PublishedRecordReadModel(
            $database,
            $recordModel,
            $definitions,
            $types,
            new FileReadModel($driver),
            new PublicCmsUrlBuilder(),
            $enabledModules,
        );
        $publicCs = PublicLocaleResolution::route('cs', 'cs', '');
        $publicEn = PublicLocaleResolution::route('cs', 'en', '');
        $repeaterFieldId = $definitions->saveField($typeId, null, [
            'name' => 'Sections', 'code' => 'sections', 'kind' => 'repeater', 'row_id' => $rowId,
            'required' => 1, 'localized' => 0, 'sort_order' => 200,
        ]);
        $definitions->saveRepeaterFields($repeaterFieldId, [
            ['id' => null, 'name' => 'Title', 'code' => 'title', 'kind' => 'text', 'required' => 1, 'localized' => 1],
            ['id' => null, 'name' => 'Content', 'code' => 'content', 'kind' => 'richtext', 'required' => 0, 'localized' => 1],
            ['id' => null, 'name' => 'Note', 'code' => 'note', 'kind' => 'textarea', 'required' => 0, 'localized' => 0],
        ]);
        $children = array_column($definitions->repeaterFields($repeaterFieldId), null, 'code');
        $titleFieldId = (int) $children['title']['id'];
        $contentFieldId = (int) $children['content']['id'];
        $noteFieldId = (int) $children['note']['id'];
        $repeaterKey = 'field_' . $repeaterFieldId;
        $repeaterLifecycle = $this->lifecycle($database, $module, new AuditLogService($database));
        $czechRepeater = [
            'locale' => 'cs', 'title' => 'Integration record', 'publication_state' => '2',
            $repeaterKey . '_present' => '1',
            $repeaterKey => [
                'order' => ['new_first', 'new_second'],
                'items' => [
                    'new_first' => [
                        'field_' . $titleFieldId => 'První sekce',
                        'field_' . $contentFieldId => '<p>První obsah</p>',
                        'field_' . $noteFieldId => 'Sdílená první poznámka',
                    ],
                    'new_second' => [
                        'field_' . $titleFieldId => 'Druhá sekce',
                        'field_' . $contentFieldId => '<p>Druhý obsah</p>',
                        'field_' . $noteFieldId => 'Sdílená druhá poznámka',
                    ],
                ],
            ],
        ];
        $invalidRepeater = $czechRepeater;
        unset($invalidRepeater[$repeaterKey]['items']['new_first']['field_' . $titleFieldId]);
        try {
            $repeaterLifecycle->save(7, $id, CmsContentInput::fromEditor($invalidRepeater), RecordData::fromEditor($invalidRepeater));
            self::fail('Published repeater accepted a missing required child value.');
        } catch (DefinitionValidationException $exception) {
            self::assertArrayHasKey($repeaterKey, $exception->errors());
            self::assertSame(0, $database->table('cms_content_repeater_item')->where('record_id', $id)->where('field_id', $repeaterFieldId)->countAllResults());
        }
        $repeaterLifecycle->save(7, $id, CmsContentInput::fromEditor($czechRepeater), RecordData::fromEditor($czechRepeater));
        $storedItems = $database->table('cms_content_repeater_item')
            ->where('record_id', $id)->where('field_id', $repeaterFieldId)->orderBy('sort_order')->getArray();
        self::assertCount(2, $storedItems);
        $firstItemId = (int) $storedItems[0]['id'];
        $secondItemId = (int) $storedItems[1]['id'];

        $czechRepeater[$repeaterKey] = [
            'order' => [(string) $secondItemId, (string) $firstItemId],
            'items' => [
                (string) $secondItemId => [
                    'id' => (string) $secondItemId,
                    'field_' . $titleFieldId => 'Druhá sekce upravená',
                    'field_' . $contentFieldId => '<p>Druhý obsah upravený</p>',
                    'field_' . $noteFieldId => 'Sdílená druhá poznámka',
                ],
                (string) $firstItemId => [
                    'id' => (string) $firstItemId,
                    'field_' . $titleFieldId => 'První sekce',
                    'field_' . $contentFieldId => '<p>První obsah</p>',
                    'field_' . $noteFieldId => 'Sdílená první poznámka',
                ],
            ],
        ];
        $repeaterLifecycle->save(7, $id, CmsContentInput::fromEditor($czechRepeater), RecordData::fromEditor($czechRepeater));
        self::assertSame([$secondItemId, $firstItemId], array_map(
            static fn(array $item): int => (int) $item['id'],
            $database->table('cms_content_repeater_item')->where('record_id', $id)->where('field_id', $repeaterFieldId)->orderBy('sort_order')->getArray(),
        ));

        $englishRepeater = [
            'locale' => 'en', 'title' => 'English draft', 'publication_state' => '1',
            $repeaterKey . '_present' => '1',
            $repeaterKey => [
                'order' => [(string) $firstItemId, (string) $secondItemId],
                'items' => [
                    (string) $firstItemId => [
                        'id' => (string) $firstItemId,
                        'field_' . $titleFieldId => 'First section',
                        'field_' . $contentFieldId => '<p>First content</p>',
                        'field_' . $noteFieldId => 'Sdílená první poznámka',
                    ],
                    (string) $secondItemId => [
                        'id' => (string) $secondItemId,
                        'field_' . $titleFieldId => 'Second section',
                        'field_' . $contentFieldId => '<p>Second content</p>',
                        'field_' . $noteFieldId => 'Sdílená druhá poznámka',
                    ],
                ],
            ],
        ];
        $repeaterLifecycle->save(7, $id, CmsContentInput::fromEditor($englishRepeater), RecordData::fromEditor($englishRepeater));
        $repeaterDefinition = array_values(array_filter(
            $definitions->fields($typeId),
            static fn(array $field): bool => (int) $field['id'] === $repeaterFieldId,
        ));
        $czechItems = $recordModel->repeaterItemsForRecords([$id], 'cs', $repeaterDefinition)[$id][$repeaterFieldId];
        $englishItems = $recordModel->repeaterItemsForRecords([$id], 'en', $repeaterDefinition)[$id][$repeaterFieldId];
        self::assertSame([$firstItemId, $secondItemId], array_map(static fn($item): int => $item->getId(), $czechItems));
        self::assertSame([$firstItemId, $secondItemId], array_map(static fn($item): int => $item->getId(), $englishItems));
        self::assertSame(['První sekce', 'Druhá sekce upravená'], array_map(static fn($item): mixed => $item->getField('title')->getValue(), $czechItems));
        self::assertSame(['First section', 'Second section'], array_map(static fn($item): mixed => $item->getField('title')->getValue(), $englishItems));
        self::assertSame('Sdílená první poznámka', $englishItems[0]->getField('note')->getValue());
        self::assertSame(8, $database->table('cms_content_repeater_item_translation_value')->where('record_id', $id)->countAllResults());

        $publicRepeated = $public->record('integration', $id, $publicCs)?->getRepeatedField('sections');
        self::assertNotNull($publicRepeated);
        self::assertSame([$firstItemId, $secondItemId], array_map(static fn($item): int => $item->getId(), $publicRepeated));
        self::assertSame('První sekce', $publicRepeated[0]->getField('title')->getValue());

        $czechRepeater[$repeaterKey] = [
            'order' => [(string) $firstItemId],
            'items' => [(string) $firstItemId => [
                'id' => (string) $firstItemId,
                'field_' . $titleFieldId => 'První sekce',
                'field_' . $contentFieldId => '<p>První obsah</p>',
                'field_' . $noteFieldId => 'Sdílená první poznámka',
            ]],
        ];
        $repeaterLifecycle->save(7, $id, CmsContentInput::fromEditor($czechRepeater), RecordData::fromEditor($czechRepeater));
        self::assertSame(1, $database->table('cms_content_repeater_item')->where('record_id', $id)->where('field_id', $repeaterFieldId)->countAllResults());
        self::assertSame(0, $database->table('cms_content_repeater_item_translation_value')->where('item_id', $secondItemId)->countAllResults());
        self::assertSame(0, $database->table('cms_content_repeater_item_value')->where('item_id', $secondItemId)->countAllResults());
        $definitions->saveRepeaterFields($repeaterFieldId, [
            ['id' => $titleFieldId, 'name' => 'Heading', 'code' => 'title', 'kind' => 'text', 'required' => 1, 'localized' => 1],
            ['id' => $noteFieldId, 'name' => 'Note', 'code' => 'note', 'kind' => 'textarea', 'required' => 0, 'localized' => 0],
        ]);
        self::assertNotNull($this->row($database->table('cms_content_repeater_field')->where('id', $contentFieldId)->first())['archived_at']);
        self::assertSame(2, $database->table('cms_content_repeater_item_translation_value')->where('child_field_id', $contentFieldId)->countAllResults());
        $publicRecord = $public->record('integration', $id, $publicCs);
        self::assertNotNull($publicRecord);
        self::assertInstanceOf(PublishedMediaInterface::class, $publicRecord);
        self::assertArrayNotHasKey('sections', $publicRecord->getFields());
        self::assertSame('Integration record', $publicRecord->getTitle());
        self::assertInstanceOf(\DateTimeImmutable::class, $publicRecord->getUpdatedAt());
        self::assertTrue($publicRecord->isAuthorShown());
        self::assertNotNull($publicRecord->getPublicUrl());
        self::assertSame('Text', $publicRecord->getField('text')->getValue());
        self::assertSame([$firstItemId], array_map(static fn($item): int => $item->getId(), $publicRecord->getRepeatedField('sections')));
        $searched = $public->collection(
            'integration',
            $publicCs,
            search: 'Text',
            searchFields: ['text'],
            ranges: ['integer' => ['from' => '-100', 'to' => '100']],
            sortBy: 'integer',
            sortDirection: 'asc',
        );
        self::assertSame(1, $searched->getTotal());
        self::assertSame([$id], array_map(static fn($item): int => $item->getId(), $searched->getItems()));
        try {
            $publicRecord->getField('missing');
            self::fail('Unknown field code was accepted.');
        } catch (InvalidArgumentException) {
            // Unknown field codes indicate an invalid application contract
        }
        self::assertSame($galleryIds, array_map(static fn($file): int => $file->id(), $publicRecord->getGallery()));
        self::assertNull($publicRecord->getThumbnail());
        self::assertSame([$attachmentIds[1]], array_map(static fn($file): int => $file->id(), $publicRecord->getAttachments()));
        self::assertCount(1, $publicRecord->getFieldFiles('thumbnail'));
        self::assertSame([], $publicRecord->getFieldFiles('media_gallery'));
        $replacementThumbnailId = $files->createIdentity($module->code(), $id, 'thumbnail', 'image');
        $files->updateImageSource($replacementThumbnailId, 'replacement-asset', 'replacement-version', new ImageOriginalMetadata('replacement.png', ImageFormat::Png, 12, 2, 2));
        self::assertSame($replacementThumbnailId, $public->record('integration', $id, $publicCs)?->getThumbnail()?->id());
        $files->remove($replacementThumbnailId);
        try {
            $publicRecord->getFieldFiles('missing');
            self::fail('Unknown media field code was accepted.');
        } catch (InvalidArgumentException) {
            // Unknown field codes indicate an invalid application contract
        }
        $sitemapEntries = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($public, $publicCs): array {
            $entries = iterator_to_array($public->sitemapEntries($publicCs));
            self::assertSame(2, $measurements->queryCount());

            return $entries;
        });
        self::assertContains($publicRecord->getPublicUrl(), array_map(static fn($entry): string => $entry->getPublicUrl(), $sitemapEntries));
        $recordEntry = array_values(array_filter(
            $sitemapEntries,
            static fn($entry): bool => $entry->getPublicUrl() === $publicRecord->getPublicUrl(),
        ))[0];
        self::assertSame('integration', $recordEntry->getTypeCode());
        self::assertInstanceOf(\DateTimeImmutable::class, $recordEntry->getUpdatedAt());
        $database->statement("UPDATE cms_content_record SET updated_at = '2030-01-01 00:00:00' WHERE id = ?", [$id]);
        $database->statement("UPDATE cms_content_record_translation SET updated_at = '2031-01-01 00:00:00' WHERE record_id = ? AND locale = 'cs'", [$id]);
        $database->statement("UPDATE cms_route SET updated_at = '2032-01-01 00:00:00' WHERE module_code = ? AND entity_id = ? AND locale = 'cs'", [$module->code(), $id]);
        $updatedEntry = array_values(array_filter(
            iterator_to_array($public->sitemapEntries($publicCs)),
            static fn($entry): bool => $entry->getPublicUrl() === $publicRecord->getPublicUrl(),
        ))[0];
        self::assertSame('2032-01-01', $updatedEntry->getUpdatedAt()->format('Y-m-d'));
        $database->statement("UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE module_code = ? AND entity_id = ? AND locale = 'cs'", [$module->code(), $id]);
        self::assertNotContains($publicRecord->getPublicUrl(), array_map(
            static fn($entry): string => $entry->getPublicUrl(),
            iterator_to_array($public->sitemapEntries($publicCs)),
        ));
        $database->statement("UPDATE cms_route SET deleted_at = NULL WHERE module_code = ? AND entity_id = ? AND locale = 'cs'", [$module->code(), $id]);
        self::assertSame([], iterator_to_array($public->sitemapEntries($publicEn)));
        $disabledModules = self::createStub(PublicModuleStateResolverInterface::class);
        $disabledModules->method('isDiscoveredInstalledAndEnabled')->willReturn(false);
        $disabledPublic = new PublishedRecordReadModel(
            $database,
            $recordModel,
            $definitions,
            $types,
            new FileReadModel($driver),
            new PublicCmsUrlBuilder(),
            $disabledModules,
        );
        self::assertSame([], iterator_to_array($disabledPublic->sitemapEntries($publicCs)));
        self::assertNull($disabledPublic->record('integration', $id, $publicCs));
        self::assertSame([], $disabledPublic->collection('integration', $publicCs)->getItems());
        self::assertSame([], $disabledPublic->others('integration', $id, $publicCs));
        self::assertFalse($disabledPublic->isPublicInAnyLocale('integration', $id));
        self::assertNull($public->record('integration', $id, $publicEn));
        $adminTranslation = (new CmsContentReadModel($database, $module->contentDefinition()))->translation($id, 'cs');
        self::assertNotNull($adminTranslation);
        self::assertArrayNotHasKey('summary', $adminTranslation);
        self::assertArrayNotHasKey('content', $adminTranslation);
        self::assertNull($public->record('other', $targetId, $publicCs));
        self::assertSame([$target2], array_map(static fn($record): int => $record->getId(), $public->related('integration', $id, 'related', $publicCs)));
        self::assertSame([], $public->relatedForRecords('integration', [$targetId], 'related', $publicCs)[$targetId]);
        try {
            $public->record('unknown_type', $id, $publicCs);
            self::fail('Unknown Content Type accepted.');
        } catch (InvalidArgumentException) {
            // Unknown codes indicate an invalid application contract.
        }
        try {
            $public->related('integration', $id, 'unknown_relation', $publicCs);
            self::fail('Unknown relation field accepted.');
        } catch (InvalidArgumentException) {
            // Unknown codes indicate an invalid application contract.
        }
        self::assertSame('Cílový záznam', $recordModel->relationRowsForSources([$id], [$relationFieldId], 'en')[0]['title']);
        self::assertSame(1, $public->referencing('integration', 'related', $target2, $publicCs)->getTotal());
        self::assertSame(0, $public->referencing('integration', 'related', $targetId, $publicCs)->getTotal());
        self::assertSame(1, $public->collection('integration', $publicCs, filters: ['select' => 'first'], relations: ['related' => [$target2, $targetId]])->getTotal());
        self::assertSame(1, $public->collection('integration', $publicCs, relationPresence: ['related' => true])->getTotal());
        self::assertSame(0, $public->collection('integration', $publicCs, relationPresence: ['related' => false])->getTotal());
        $storedRelations = $database->table('cms_content_record_relation')
            ->where('source_record_id', $id)->where('field_id', $relationFieldId)->getArray();
        $database->table('cms_content_record_relation')
            ->where('source_record_id', $id)->where('field_id', $relationFieldId)->delete();
        self::assertSame(1, $public->collection('integration', $publicCs, relationPresence: ['related' => false])->getTotal());
        self::assertSame(0, $public->collection('integration', $publicCs, relationPresence: ['related' => true])->getTotal());
        foreach ($storedRelations as $relation) {
            unset($relation['id']);
            $database->table('cms_content_record_relation')->insert($relation);
        }
        self::assertSame([$id], array_map(
            static fn($item): int => $item->getId(),
            $public->collection('integration', $publicCs, search: 'Cílový', searchRelations: ['related'])->getItems(),
        ));
        self::assertSame([$id], array_map(
            static fn($item): int => $item->getId(),
            $public->collection('integration', $publicCs, sortBy: 'related.title')->getItems(),
        ));
        self::assertSame([], $public->collection('integration', $publicCs, relations: ['related' => $target2], page: 2, perPage: 1)->getItems());
        try {
            $public->collection('integration', $publicCs, filters: ['select' => 'unknown']);
            self::fail('Invalid public filter accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Invalid option field filter.', $exception->getMessage());
        }
        try {
            $public->collection('integration', $publicCs, filters: ['sections' => 'unsupported']);
            self::fail('Repeater filter accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown scalar field filter: sections', $exception->getMessage());
        }
        $secondPublished = ['locale' => 'cs', 'title' => 'Druhý cíl', 'publication_state' => '2'];
        $this->lifecycle($database, $other, new AuditLogService($database))->save(
            7,
            $targetId,
            CmsContentInput::fromEditor($secondPublished),
            RecordData::fromEditor($secondPublished),
        );
        self::assertSame([$target2, $targetId], array_map(static fn($record): int => $record->getId(), $public->related('integration', $id, 'related', $publicCs)));
        $database->table('cms_content_record_translation')->where('record_id', $targetId)->where('locale', 'cs')
            ->set(['published_at' => '2026-01-01 00:00:00'])->update();
        $database->table('cms_content_record_translation')->where('record_id', $target2)->where('locale', 'cs')
            ->set(['published_at' => '2026-01-02 00:00:00'])->update();
        self::assertSame([$targetId], array_map(
            static fn($record): int => $record->getId(),
            $public->others('other', $target2, $publicCs, limit: 1),
        ));
        self::assertSame([], $public->others('other', $target2, $publicCs, limit: 1, tag: 'unavailable'));
        self::assertTrue($public->isPublicInAnyLocale('other', $targetId));
        $database->table('cms_content_record_translation')->where('record_id', $targetId)->where('locale', 'cs')
            ->set(['published_at' => '2099-01-01 00:00:00'])->update();
        self::assertSame([$target2], array_map(static fn($record): int => $record->getId(), $public->related('integration', $id, 'related', $publicCs)));
        $database->table('cms_content_record_translation')->where('record_id', $targetId)->where('locale', 'cs')
            ->set(['published_at' => '2026-01-01 00:00:00'])->update();
        $englishTarget = ['locale' => 'en', 'title' => 'English target', 'publication_state' => '2'];
        $this->lifecycle($database, $other, new AuditLogService($database))->save(
            7,
            $target2,
            CmsContentInput::fromEditor($englishTarget),
            RecordData::fromEditor($englishTarget),
        );
        self::assertSame('English target', $public->record('other', $target2, $publicEn)?->getTitle());
        $database->table('system_language')->where('code', 'en')->set(['enabled' => 0])->update();
        self::assertNull($public->record('other', $target2, $publicEn));
        $database->table('system_language')->where('code', 'en')->set(['enabled' => 1])->update();
        $database->table('cms_route')->where('module_code', $other->code())->where('entity_id', $target2)->delete();
        self::assertNull($public->record('other', $target2, $publicCs)?->getPublicUrl());
        $scalarValues = $recordModel->fieldValues($typeId, [$id], 'cs', $definitions->fields($typeId));
        self::assertNotContains('relation', array_map(static fn($value): string => $value->getKind(), $scalarValues[$id]));
        self::assertTrue($definitions->fieldHasMultipleRelations($relationFieldId));
        $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $modules->register($module);
        $router = new Router();
        (new AdminModuleRouteRegistrar($modules))->registerRoutes($router);
        (new RelationOptionsRouteRegistrar())->registerRoutes($router);
        $editor = (new RecordEditorDefinition($module, new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets), $types, new UrlGenerator($router), new AdminModuleRouteResolver($modules)))
            ->create(['id' => $id, 'translations' => [['publication_state' => 2]]], 'cs', true);
        $renderContext = new AdminEditorRenderContext(values: (new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets))->editorValues($id, 'cs'));
        $fieldRenderer = new AdminEditorFieldRenderer();
        $renderedRelations = [];
        foreach ($editor->tabs()[0]->blocks() as $block) {
            if (!$block instanceof SectionBlock || $block->id() !== 'content-fields') {
                continue;
            }
            foreach ($block->blocks() as $group) {
                if (!$group instanceof FieldGroupBlock) {
                    continue;
                }
                foreach ($group->columns() as $column) {
                    $field = $column->field();
                    if ($field instanceof SelectField) {
                        $renderedRelations[$field->name()] = $fieldRenderer->render($field, $renderContext, 'record-test');
                    }
                }
            }
        }
        self::assertStringContainsString('data-lemonade-option-source="async"', $renderedRelations['field_' . $singleFieldId]);
        self::assertStringContainsString('relation-picker', $renderedRelations['field_' . $singleFieldId]);
        self::assertStringContainsString('selected', $renderedRelations['field_' . $singleFieldId]);
        self::assertStringContainsString('multiple', $renderedRelations['field_' . $relationFieldId]);
        self::assertStringContainsString('data-lemonade-ordered', $renderedRelations['field_' . $relationFieldId]);
        $oldRelationInput = [
            'field_' . $relationFieldId . '_present' => '1',
            'field_' . $relationFieldId => [(string) $targetId, (string) $target2],
        ];
        $oldOptions = (new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets))
            ->relationOptionsWithInput($id, 'cs', $oldRelationInput);
        self::assertSame([$targetId, $target2], array_keys($oldOptions[$relationFieldId]));
        $forgedInput = ['field_' . $relationFieldId => ['99999999']] + $oldRelationInput;
        $forgedOptions = (new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets))
            ->relationOptionsWithInput($id, 'cs', $forgedInput);
        self::assertArrayNotHasKey(99999999, $forgedOptions[$relationFieldId]);
        $oldEditor = (new RecordEditorDefinition($module, new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets), $types, new UrlGenerator($router), new AdminModuleRouteResolver($modules)))
            ->create(['id' => $id, 'translations' => [['publication_state' => 2]]], 'cs', true, oldInput: $oldRelationInput);
        $oldContext = new AdminEditorRenderContext(values: $renderContext->values(), oldInput: $oldRelationInput);
        foreach ($oldEditor->tabs()[0]->blocks() as $block) {
            if (!$block instanceof SectionBlock || $block->id() !== 'content-fields') {
                continue;
            }
            foreach ($block->blocks() as $group) {
                if (!$group instanceof FieldGroupBlock) {
                    continue;
                }
                foreach ($group->columns() as $column) {
                    $field = $column->field();
                    if ($field instanceof SelectField && $field->name() === 'field_' . $relationFieldId) {
                        $html = $fieldRenderer->render($field, $oldContext, 'record-test');
                        $first = strpos($html, 'value="' . $targetId . '" selected');
                        $second = strpos($html, 'value="' . $target2 . '" selected');
                        self::assertIsInt($first);
                        self::assertIsInt($second);
                        self::assertLessThan($second, $first);
                    }
                }
            }
        }
        $resources = new ViewResourceRegistry();
        $resources->register('content-types', dirname(__DIR__, 2) . '/src/Resources/views');
        $adminFile = (new ReflectionClass(AdminEditorRenderer::class))->getFileName();
        self::assertIsString($adminFile);
        $resources->register('admin', dirname($adminFile, 4) . '/Resources/views');
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('get')->willReturnArgument(0);
        $helpers = new ViewHelpers(new UrlGenerator($router), new CsrfViewHelper(new CsrfTokenManager(self::createStub(SessionInterface::class))), $translator, new LocalizationConfig('cs', 'en', ['cs', 'en'], new LocalizationUrlConfig(false, '', '', 'locale', false)));
        $view = new View(resources: $resources);
        $view->share('helpers', $helpers);
        $editorHtml = (new AdminEditorRenderer($view, $helpers))->render($editor, $renderContext);
        self::assertStringContainsString('name="field_' . $relationFieldId . '_present"', $editorHtml);
        self::assertStringContainsString('name="field_' . $relationFieldId . '[]"', $editorHtml);
        self::assertStringContainsString('data-lemonade-repeatable-group', $editorHtml);
        self::assertStringContainsString('data-lemonade-sortable', $editorHtml);
        self::assertStringContainsString('name="field_' . $repeaterFieldId . '[order][]"', $editorHtml);
        self::assertStringContainsString('name="field_' . $repeaterFieldId . '[items][' . $firstItemId . '][field_' . $titleFieldId . ']"', $editorHtml);
        $psrFactory = new Psr17Factory();
        $languages = new LanguageRegistry($database, new CacheManager(new ArrayCacheItemPool()));
        $urls = new UrlGenerator($router);
        $routes = new AdminModuleRouteResolver($modules);
        $actions = new ModuleActionRegistry();
        $actions->register($module->code(), new ModuleActionDefinition('delete', $module->code() . '.delete', 'admin.common.delete'), 'delete');
        $actions->register($module->code(), new ModuleActionDefinition('restore', $module->code() . '.restore', 'admin.common.restore'), 'restore');
        $gridProvider = new RecordDataGrid(
            $module,
            new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets),
            $types,
            new CmsContentReadModel($database, $module->contentDefinition()),
            $allowTargets,
            $translator,
            new ModuleActionPresentationFactory($actions, $routes, $allowTargets, $translator, $urls),
            $routes,
            $urls,
            $languages,
            $grid,
            $recordModel,
        );
        $gridDefinition = $gridProvider->dataGridDefinition();
        self::assertNotNull($gridDefinition->filter('field_' . $relationFieldId)?->asyncUrl());
        $columnKeys = array_map(static fn($column): string => $column->key(), $gridDefinition->columns());
        self::assertContains('field_' . $relationFieldId, $columnKeys);
        self::assertNotContains('field_' . $repeaterFieldId, $columnKeys);
        foreach ($definitions->fields($typeId) as $field) {
            if (in_array($field['kind'], ['image', 'gallery', 'file', 'files'], true)) {
                self::assertNotContains('field_' . $field['id'], $columnKeys);
            }
        }
        $gridResult = $gridProvider->execute($relationQuery);
        self::assertSame(1, $gridResult->total());
        $gridCells = $gridResult->items()[0]->toArray()['cells'];
        self::assertArrayHasKey('field_' . $relationFieldId, $gridCells);
        self::assertSame('Cílový záznam, Druhý cíl', $gridCells['field_' . $relationFieldId]);
        $optionsController = new RelationOptionsController($definitions, $recordModel, $languages, $allowTargets, new Responses(new ResponseBuilder($psrFactory, $psrFactory)));
        $optionsResponse = $optionsController->index((new ServerRequest('GET', '/'))->withQueryParams([
            'field' => $relationFieldId, 'source' => $id, 'locale' => 'cs', 'q' => 'Cílový', 'page' => 1,
        ]));
        self::assertSame(200, $optionsResponse->getStatusCode());
        $optionsBody = json_decode((string) $optionsResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['value' => (string) $target2, 'label' => 'Cílový záznam']], $optionsBody['items']);
        $unfilteredOptions = $optionsController->index((new ServerRequest('GET', '/'))->withQueryParams([
            'field' => $relationFieldId, 'source' => $id, 'locale' => 'cs',
        ]));
        $unfilteredBody = json_decode((string) $unfilteredOptions->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $unfilteredBody['items']);
        $selectedOptions = $optionsController->index((new ServerRequest('GET', '/'))->withQueryParams([
            'field' => $relationFieldId, 'locale' => 'cs', 'selected' => $target2,
        ]));
        self::assertSame(200, $selectedOptions->getStatusCode());
        $selectedBody = json_decode((string) $selectedOptions->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['value' => (string) $target2, 'label' => 'Cílový záznam']], $selectedBody['items']);
        foreach ([['locale' => 'xx'], ['page' => '0'], ['field' => []], ['q' => []]] as $invalidQuery) {
            $response = $optionsController->index((new ServerRequest('GET', '/'))->withQueryParams($invalidQuery + [
                'field' => $relationFieldId, 'source' => $id, 'locale' => 'cs', 'page' => '1', 'q' => '',
            ]));
            self::assertSame(400, $response->getStatusCode());
        }
        self::assertSame('English target', $recordModel->relationRowsForSources([$id], [$relationFieldId], 'en')[0]['title']);
        self::assertSame((string) $target2, (string) $recordModel->searchRelationTargets($secondType, 'en', 'English', 25, 0)[0]['id']);
        $reordered = ['field_' . $relationFieldId => [(string) $targetId, (string) $target2]] + $relations;
        $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($reordered), RecordData::fromEditor($reordered));
        self::assertSame([$targetId, $target2], $this->relationIds($recordModel, $id, $relationFieldId));
        self::assertSame([$targetId, $target2], array_map(static fn($record): int => $record->getId(), $public->related('integration', $id, 'related', $publicCs)));
        $translation = ['locale' => 'en', 'title' => 'English draft'];
        $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($translation), RecordData::fromEditor($translation));
        self::assertSame([$targetId, $target2], $this->relationIds($recordModel, $id, $relationFieldId));
        try {
            $clearFromTranslation = ['field_' . $singleFieldId . '_present' => '1', 'field_' . $singleFieldId => ''] + $translation;
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($clearFromTranslation), RecordData::fromEditor($clearFromTranslation));
            self::fail('Draft translation cleared a required shared relation of published content.');
        } catch (DefinitionValidationException) {
            self::assertSame([$targetId], $this->relationIds($recordModel, $id, $singleFieldId));
        }
        foreach ([
            ['field_' . $relationFieldId => [(string) $targetId, (string) $targetId]],
            ['field_' . $relationFieldId => [(string) $targetId, (string) $id]],
            ['field_' . $relationFieldId => ['invalid']],
            ['field_' . $relationFieldId . '_present' => '0'],
            ['field_' . $singleFieldId => [(string) $targetId, (string) $target2]],
            ['field_' . $singleFieldId => '99999999'],
        ] as $invalid) {
            try {
                $payload = $invalid + $reordered;
                $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($payload), RecordData::fromEditor($payload));
                self::fail('Invalid relation accepted.');
            } catch (DefinitionValidationException) {
                self::assertSame([$targetId, $target2], $this->relationIds($recordModel, $id, $relationFieldId));
            }
        }
        $emptyMultiple = ['field_' . $relationFieldId => null] + $reordered;
        $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($emptyMultiple), RecordData::fromEditor($emptyMultiple));
        self::assertSame([], $this->relationIds($recordModel, $id, $relationFieldId));
        self::assertFalse($definitions->fieldHasMultipleRelations($relationFieldId));
        $beforeFailedWrite = $this->snapshot($database);
        $database->statement("CREATE TRIGGER relation_reject_test BEFORE INSERT ON cms_content_record_relation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced rollback'");
        try {
            $rollbackInput = ['field_' . $relationFieldId => [(string) $target2], 'field_' . $ids['integer'] => '42'] + $emptyMultiple;
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($rollbackInput), RecordData::fromEditor($rollbackInput));
            self::fail('Failed relation insert committed scalar content.');
        } catch (DatabaseException) {
            self::assertSame($beforeFailedWrite, $this->snapshot($database));
            self::assertSame([], $this->relationIds($recordModel, $id, $relationFieldId));
        } finally {
            $database->statement('DROP TRIGGER relation_reject_test');
        }
        $withoutPermission = new AuthorizationService(new class implements CurrentPrincipalProviderInterface {
            public function currentPrincipal(): ?AdminPrincipalInterface
            {
                return null;
            }

            public function currentUser(): ?AuthenticatedUser
            {
                return null;
            }
        }, $database);
        $deniedInputOptions = (new RecordValues($module, $definitions, $recordModel, $types, $validation, $withoutPermission))
            ->relationOptionsWithInput($id, 'cs', [
                'field_' . $relationFieldId . '_present' => '1',
                'field_' . $relationFieldId => [(string) $target2],
            ]);
        self::assertArrayNotHasKey($target2, $deniedInputOptions[$relationFieldId] ?? []);
        $deniedOptions = (new RelationOptionsController($definitions, $recordModel, $languages, $withoutPermission, new Responses(new ResponseBuilder($psrFactory, $psrFactory))))->index(
            (new ServerRequest('GET', '/'))->withQueryParams(['field' => $relationFieldId, 'source' => $id, 'locale' => 'cs']),
        );
        self::assertSame(403, $deniedOptions->getStatusCode());
        $deniedGridOptions = (new RelationOptionsController($definitions, $recordModel, $languages, $withoutPermission, new Responses(new ResponseBuilder($psrFactory, $psrFactory))))->index(
            (new ServerRequest('GET', '/'))->withQueryParams(['field' => $relationFieldId, 'locale' => 'cs']),
        );
        self::assertSame(403, $deniedGridOptions->getStatusCode());
        $deniedLifecycle = $this->lifecycle($database, $module, new AuditLogService($database), $withoutPermission);
        $addTarget = ['field_' . $relationFieldId => [(string) $target2]] + $emptyMultiple;
        try {
            $deniedLifecycle->save(7, $id, CmsContentInput::fromEditor($addTarget), RecordData::fromEditor($addTarget));
            self::fail('Target without view permission accepted.');
        } catch (DefinitionValidationException) {
            self::assertSame([], $this->relationIds($recordModel, $id, $relationFieldId));
        }
        $invalidScalar = ['field_' . $ids['integer'] => '1.5'] + $addTarget;
        try {
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($invalidScalar), RecordData::fromEditor($invalidScalar));
            self::fail('Invalid scalar value accepted with relation change.');
        } catch (DefinitionValidationException) {
            self::assertSame([], $this->relationIds($recordModel, $id, $relationFieldId));
        }
        $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($addTarget), RecordData::fromEditor($addTarget));
        self::assertSame([$target2], $this->relationIds($recordModel, $id, $relationFieldId));
        $this->lifecycle($database, $other, new AuditLogService($database))->delete(7, $targetId);
        self::assertSame([$targetId], $this->relationIds($recordModel, $id, $singleFieldId));
        try {
            $deletedTargetInput = ['field_' . $relationFieldId => [(string) $targetId]] + $addTarget;
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($deletedTargetInput), RecordData::fromEditor($deletedTargetInput));
            self::fail('New link to a deleted target accepted.');
        } catch (DefinitionValidationException) {
            self::assertSame([$target2], $this->relationIds($recordModel, $id, $relationFieldId));
        }
        $relationValues = new RecordValues($module, $definitions, $recordModel, $types, $validation, $allowTargets);
        self::assertStringContainsString('[deleted]', $relationValues->relationOptions($id, 'cs')[$singleFieldId][$targetId]);
        $deletedTargetQuery = new DataGridQuery(1, 25, 'title', 'asc', '', ['field_' . $singleFieldId => (string) $targetId]);
        self::assertSame(1, $gridProvider->execute($deletedTargetQuery)->total());
        $deletedFilterOptions = $optionsController->index((new ServerRequest('GET', '/'))->withQueryParams([
            'field' => $singleFieldId, 'locale' => 'cs', 'selected' => $targetId,
        ]));
        $deletedFilterBody = json_decode((string) $deletedFilterOptions->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['value' => (string) $targetId, 'label' => 'Druhý cíl [deleted]']], $deletedFilterBody['items']);
        self::assertSame([$target2], array_map(static fn($record): int => $record->getId(), $public->related('integration', $id, 'related', $publicCs)));
        try {
            $clearedRequired = ['field_' . $singleFieldId => ''] + $addTarget;
            $relationLifecycle->save(7, $id, CmsContentInput::fromEditor($clearedRequired), RecordData::fromEditor($clearedRequired));
            self::fail('Published record accepted with an empty required relation.');
        } catch (DefinitionValidationException) {
            self::assertSame([$targetId], $this->relationIds($recordModel, $id, $singleFieldId));
        }
        $definitions->saveField($typeId, $relationFieldId, ['archived_at' => date('Y-m-d H:i:s')]);
        self::assertSame([$target2], $this->relationIds($recordModel, $id, $relationFieldId));
        $definitions->updateType($typeId, ['enabled' => 0], 1);
        self::assertNull($public->record('integration', $id, $publicCs));
        self::assertNotContains($publicRecord->getPublicUrl(), array_map(
            static fn($entry): string => $entry->getPublicUrl(),
            iterator_to_array($public->sitemapEntries($publicCs)),
        ));
        self::assertSame([], $public->collection('integration', $publicCs)->getItems());
        try {
            $lifecycle->createDraft(7, 'cs');
            self::fail('Disabled type allowed creation');
        } catch (DomainException $exception) {
            self::assertSame('records.validation.type_disabled', $exception->getMessage());
        }
        self::assertSame(3, $database->table('cms_content_record')->countAllResults());
        self::assertNotNull((new CmsContentReadModel($database, $module->contentDefinition()))->findForEditor($id));

        $this->lifecycle($database, $other, new AuditLogService($database))->createDraft(7, 'de');
        $database->table('system_language')->where('code', 'de')->set(['enabled' => 0])->update();
        $ownRead = new CmsContentReadModel($database, $module->contentDefinition());
        self::assertNotContains('de', array_column($ownRead->dataGridLocales(), 'code'));
        self::assertContains('de', array_column($otherRead->dataGridLocales(), 'code'));
        self::assertSame(1, $grid->listForDataGrid(new DataGridQuery(1, 25, 'title', 'asc', '', []), 'cs')->total());
        $extraTarget = $this->lifecycle($database, $other, new AuditLogService($database))->createDraft(7, 'cs');
        $otherRow = $definitions->saveRow($secondType, null, 0);
        $selfField = $definitions->saveField($secondType, null, [
            'name' => 'Related', 'code' => 'related', 'kind' => 'relation', 'row_id' => $otherRow,
            'required' => 0, 'localized' => 0, 'sort_order' => 0,
            'relation_target_type_id' => $secondType, 'relation_multiple' => 0,
        ]);
        foreach ([$target2, $id] as $invalidTarget) {
            try {
                $invalidSelfRelation = ['locale' => 'cs', 'title' => 'Cílový záznam', 'publication_state' => '2', 'field_' . $selfField . '_present' => '1', 'field_' . $selfField => (string) $invalidTarget];
                $this->lifecycle($database, $other, new AuditLogService($database), $allowTargets)->save(7, $target2, CmsContentInput::fromEditor($invalidSelfRelation), RecordData::fromEditor($invalidSelfRelation));
                self::fail('Self or wrong-type relation accepted.');
            } catch (DefinitionValidationException) {
                self::assertSame([], $this->relationIds($recordModel, $target2, $selfField));
            }
        }
        $recordModel->saveRelation($target2, $secondType, $selfField, $secondType, [$extraTarget]);
        $publishedExtra = ['locale' => 'cs', 'title' => 'Extra target', 'publication_state' => '2'];
        $this->lifecycle($database, $other, new AuditLogService($database))->save(
            7,
            $extraTarget,
            CmsContentInput::fromEditor($publishedExtra),
            RecordData::fromEditor($publishedExtra),
        );
        $recordModel->saveRelation($extraTarget, $secondType, $selfField, $secondType, [$target2]);
        $publicBatch = new PublishedRecordReadModel(
            $database,
            $recordModel,
            $definitions,
            $types,
            new FileReadModel($driver),
            new PublicCmsUrlBuilder(),
            $enabledModules,
        );
        $publicBatch->relatedForRecords('other', [$target2], 'related', $publicCs);
        $singleQueryCount = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($publicBatch, $target2, $publicCs): int {
            $publicBatch->relatedForRecords('other', [$target2], 'related', $publicCs);
            return $measurements->queryCount();
        });
        $batchQueryCount = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($publicBatch, $target2, $extraTarget, $publicCs): int {
            $related = $publicBatch->relatedForRecords('other', [$target2, $extraTarget], 'related', $publicCs);
            self::assertSame([$extraTarget], array_map(static fn($record): int => $record->getId(), $related[$target2]));
            self::assertSame([$target2], array_map(static fn($record): int => $record->getId(), $related[$extraTarget]));
            return $measurements->queryCount();
        });
        self::assertSame($singleQueryCount, $batchQueryCount);
        self::assertSame([$extraTarget], array_map(
            static fn(array $row): int => (int) $row['id'],
            $recordModel->relationRowsForSources([$target2], [$selfField], 'cs'),
        ));
        $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($recordModel, $id, $target2, $singleFieldId, $selfField): void {
            $rows = $recordModel->relationRowsForSources([$id, $target2], [$singleFieldId, $selfField], 'cs');
            self::assertCount(2, $rows);
            self::assertSame(1, $measurements->queryCount());
        });
        $definitions->updateType($secondType, ['description' => 'Other content'], (int) $this->row($definitions->type($secondType))['revision']);
        $managementPrincipal = new class implements CurrentPrincipalProviderInterface {
            public function currentPrincipal(): AdminPrincipalInterface
            {
                return new LocalAdminPrincipal(new AuthenticatedUser(7, 'admin@example.test'));
            }

            public function currentUser(): AuthenticatedUser
            {
                return new AuthenticatedUser(7, 'admin@example.test');
            }
        };
        $management = new DefinitionService(
            $definitions,
            new DefinitionValidation($types, $validation),
            $types,
            new TransactionalEventProcessor($database, new AuditLogService($database), new DomainEventDispatcher(new NullLogger())),
            new LocalActorGuard($managementPrincipal),
            new AuthorizationService($managementPrincipal, $database),
        );
        $setMultiplicity = static function (int $multiple) use ($management, $secondType): void {
            $input = $management->detail($secondType);
            $definition = json_decode((string) $input['definition'], true, 512, JSON_THROW_ON_ERROR);
            $definition['fields'][0]['relation_multiple'] = $multiple;
            $input['definition'] = json_encode($definition, JSON_THROW_ON_ERROR);
            $management->save($secondType, $input);
        };
        $setMultiplicity(1);
        self::assertSame([$extraTarget], $this->relationIds($recordModel, $target2, $selfField));
        $recordModel->saveRelation($target2, $secondType, $selfField, $secondType, [$extraTarget, $targetId]);
        try {
            $setMultiplicity(0);
            self::fail('Multiple-to-single change discarded links.');
        } catch (DefinitionValidationException) {
            self::assertSame([$extraTarget, $targetId], $this->relationIds($recordModel, $target2, $selfField));
        }
        $recordModel->saveRelation($target2, $secondType, $selfField, $secondType, [$extraTarget]);
        $setMultiplicity(0);
        self::assertSame([$extraTarget], $this->relationIds($recordModel, $target2, $selfField));
        $lifecycle->delete(7, $id);
        self::assertSame([$targetId], $this->relationIds($recordModel, $id, $singleFieldId));
        self::assertNull($public->record('integration', $id, $publicCs));

        $bodyColumns = $database->select(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cms_content_record_translation' AND COLUMN_NAME IN ('summary', 'content')",
        );
        self::assertSame([], $bodyColumns);
    }

    /**
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
     * Reads one relation from the batch query used by the editor
     *
     * @return list<int>
     * @phpstan-impure
     */
    private function relationIds(RecordModel $records, int $sourceId, int $fieldId): array
    {
        return $records->relationIdsForSources([$sourceId], [$fieldId])[$sourceId][$fieldId] ?? [];
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function row(?array $row): array
    {
        self::assertNotNull($row);
        return $row;
    }

    /**
     * @return CmsContentLifecycle<RecordData>
     */
    private function lifecycle(Database $database, RecordModule $module, AuditLogWriterInterface $writer, ?AuthorizationService $authorization = null): CmsContentLifecycle
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

        return new CmsContentLifecycle($definition, new CmsContentModel($database, $definition), new CmsDomainMapping(RecordData::class), $routes, new Slugger(), $runtime, new RecordValues($module, new DefinitionModel($database), new RecordModel($database, $types), $types, $validation, $authorization ?? new AuthorizationService(self::createStub(CurrentPrincipalProviderInterface::class), $database)));
    }
}
