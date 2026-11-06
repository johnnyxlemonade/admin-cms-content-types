<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Tests\Unit;

use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Audit\AuditLogWriterInterface;
use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Auth\LocalAdminPrincipal;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\Editor\Exception\EditorCapabilityException;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Cms\ContentTypes\Actions\SetDeletedAction;
use Lemonade\Cms\ContentTypes\Actions\SetEnabledAction;
use Lemonade\Cms\ContentTypes\DefinitionService;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;

/**
 * Testuje mutace pres skutecny service, model, transakcni processor a autorizaci
 */
final class DefinitionServiceTest extends DefinitionTestCase
{
    /** @var list<array{sql:string,bindings:array<mixed>|false}> */
    private array $writes = [];
    /** @var list<DomainEvent> */
    private array $audits = [];

    /**
     * Prazdny popis odmitne create i update bez zapisu a auditu
     */
    public function testDescriptionIsRequiredForCreationAndUpdate(): void
    {
        foreach ([null, 7] as $id) {
            $input = $this->input($id !== null);
            $input['description'] = '   ';
            try {
                $this->service(true, $id !== null)->save($id, $input);
                self::fail('Missing description was accepted.');
            } catch (DefinitionValidationException $exception) {
                self::assertArrayHasKey('description', $exception->errors());
                self::assertSame([], $this->writes);
                self::assertSame([], $this->audits);
            }
        }
    }

    public function testCreateTypeAndFieldAreAuditedTogether(): void
    {
        $service = $this->service();
        self::assertSame(41, $service->save(null, $this->input()));
        self::assertCount(1, $this->audits);
        self::assertSame('cms.content-types.created', $this->audits[0]->code());
        self::assertStringContainsString('cms_content_type', $this->writes[0]['sql']);
        self::assertNotEmpty(array_filter($this->writes, static fn(array $write): bool => str_contains($write['sql'], 'INSERT INTO cms_content_field ')));
    }

    public function testUpdateKeepsIdentitiesAndAllowsLabelsHelpAndOrder(): void
    {
        $service = $this->service(true, true);
        $input = $this->input(true);
        $input['name'] = 'New title';
        $definition = json_decode($input['definition'], true, 512, JSON_THROW_ON_ERROR);
        $definition['fields'][0]['name'] = 'New label';
        $definition['fields'][0]['help'] = 'Help';
        $input['definition'] = json_encode($definition, JSON_THROW_ON_ERROR);
        self::assertSame(7, $service->save(7, $input));
        self::assertSame('cms.content-types.updated', $this->audits[0]->code());
        self::assertNotEmpty(array_filter($this->writes, static fn(array $write): bool => str_contains($write['sql'], 'UPDATE cms_content_field SET') && in_array('New label', ($write['bindings'] === false ? [] : $write['bindings']), true)));
        self::assertEmpty(array_filter($this->writes, static fn(array $write): bool => str_starts_with($write['sql'], 'DELETE')));
    }

    public function testArchiveDoesNotDeleteStoredField(): void
    {
        $service = $this->service(true, true);
        $input = $this->input(true);
        $definition = json_decode($input['definition'], true, 512, JSON_THROW_ON_ERROR);
        $definition['fields'][0]['archived'] = true;
        $definition['rows'] = [];
        $input['definition'] = json_encode($definition, JSON_THROW_ON_ERROR);
        $service->save(7, $input);
        self::assertEmpty(array_filter($this->writes, static fn(array $write): bool => str_starts_with($write['sql'], 'DELETE')));
        self::assertNotEmpty(array_filter($this->writes, static fn(array $write): bool => str_contains($write['sql'], 'archived_at = ?')));
    }

    /** @return iterable<string,array{string}> */
    public static function unsafeChanges(): iterable
    {
        foreach (['type_code', 'field_code', 'kind', 'missing', 'foreign_id', 'foreign_row', 'duplicate_code', 'duplicate_placement', 'too_wide', 'stale'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('unsafeChanges')]
    public function testUnsafeChangeIsRejectedBeforeWrites(string $change): void
    {
        $service = $this->service(true, true);
        $input = $this->input(true);
        $definition = json_decode($input['definition'], true, 512, JSON_THROW_ON_ERROR);
        switch ($change) {
            case 'type_code': $input['code'] = 'other';
                break;
            case 'field_code': $definition['fields'][0]['code'] = 'other';
                break;
            case 'kind': $definition['fields'][0]['kind'] = 'decimal';
                break;
            case 'missing': $definition['fields'] = [];
                $definition['rows'] = [];
                break;
            case 'foreign_id': $definition['fields'][0]['id'] = 99;
                break;
            case 'foreign_row': $definition['rows'][0]['id'] = 99;
                break;
            case 'duplicate_code': $definition['fields'][] = ['key' => 'new', 'name' => 'Duplicate', 'code' => 'field', 'kind' => 'text'];
                break;
            case 'duplicate_placement': $definition['rows'][] = ['columns' => [['field' => 'f8', 'span' => 12]]];
                break;
            case 'too_wide': $definition['rows'][0]['columns'][0]['span'] = 13;
                break;
            case 'stale': $input['revision'] = 0;
                break;
        }
        $input['definition'] = json_encode($definition, JSON_THROW_ON_ERROR);
        try {
            $service->save(7, $input);
            self::fail('Unsafe change was accepted.');
        } catch (DefinitionValidationException) {
            self::assertSame([], $this->writes);
            self::assertSame([], $this->audits);
        }
    }

    public function testMissingManagePermissionRejectsMutation(): void
    {
        $service = $this->service(false);
        $this->expectException(EditorCapabilityException::class);
        try {
            $service->save(null, $this->input());
        } finally {
            self::assertSame([], $this->writes);
            self::assertSame([], $this->audits);
        }
    }

    /**
     * Deaktivace zachova podrazene definice a zaznamena jednu zmenu revize
     */
    public function testDisableOnlyChangesTypeAndAudits(): void
    {
        $result = (new SetEnabledAction($this->service(true, true), false))->execute(7, []);
        self::assertTrue($result->refreshPage());
        self::assertCount(1, $this->writes);
        self::assertStringContainsString('UPDATE cms_content_type SET enabled = ?, revision = ?, updated_at = ?', $this->writes[0]['sql']);
        self::assertIsArray($this->writes[0]['bindings']);
        self::assertSame([0, 2], array_slice($this->writes[0]['bindings'], 0, 2));
        self::assertCount(1, $this->audits);
        self::assertSame('cms.content-types.disabled', $this->audits[0]->code());
        self::assertSame('7', $this->audits[0]->entityKey());
    }

    /**
     * Aktivace obnovi dostupnost bez vytvareni nove identity typu
     */
    public function testEnableActionRestoresAvailability(): void
    {
        $result = (new SetEnabledAction($this->service(true, true, false), true))->execute(7, []);
        self::assertTrue($result->successful());
        self::assertSame('content-types.actions.enabled', $result->messageKey());
        self::assertTrue($result->refreshPage());
        self::assertCount(1, $this->writes);
        self::assertIsArray($this->writes[0]['bindings']);
        self::assertSame([1, 2], array_slice($this->writes[0]['bindings'], 0, 2));
        self::assertSame('cms.content-types.enabled', $this->audits[0]->code());
    }

    /**
     * Action transport zachova serverove odmitnuti uzivatele s pouhym ctenim
     */
    public function testActionMapsPermissionDenialToForbidden(): void
    {
        try {
            (new SetEnabledAction($this->service(false, true), false))->execute(7, []);
            self::fail('Read-only user changed availability.');
        } catch (ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame([], $this->writes);
            self::assertSame([], $this->audits);
        }
    }

    /**
     * Action transport vraci not found pro neznamou identitu
     */
    public function testActionMapsMissingTypeToNotFound(): void
    {
        foreach ([null, 7] as $id) {
            try {
                (new SetEnabledAction($this->service(), false))->execute($id, []);
                self::fail('Missing type was accepted.');
            } catch (ModuleActionException $exception) {
                self::assertSame(404, $exception->status());
                self::assertSame([], $this->writes);
                self::assertSame([], $this->audits);
            }
        }
    }

    /**
     * Opakovane nastaveni stejneho stavu nezapisuje ani neaudituje
     */
    public function testSettingCurrentStateIsNoOp(): void
    {
        $this->service(true, true)->setEnabled(7, true);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->audits);
    }

    /**
     * Prime volani bez prava spravy odmita i existujici typ
     */
    public function testAvailabilityChangeRequiresManagement(): void
    {
        $this->expectException(EditorCapabilityException::class);
        try {
            $this->service(false, true)->setEnabled(7, false);
        } finally {
            self::assertSame([], $this->writes);
            self::assertSame([], $this->audits);
        }
    }

    /**
     * Neznamy typ nevede k zapisu ani udalosti
     */
    public function testAvailabilityChangeRejectsMissingType(): void
    {
        $this->expectException(\Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException::class);
        try {
            $this->service()->setEnabled(7, false);
        } finally {
            self::assertSame([], $this->writes);
            self::assertSame([], $this->audits);
        }
    }

    /**
     * Soft delete meni pouze koren; definice poli a moznosti se nemazou
     */
    public function testDeletePreservesDefinitionAndAudits(): void
    {
        $result = (new SetDeletedAction($this->service(true, true), true))->execute(7, []);
        self::assertTrue($result->successful());
        self::assertSame('content-types.actions.deleted', $result->messageKey());
        self::assertCount(1, $this->writes);
        self::assertStringContainsString('UPDATE cms_content_type SET deleted_at = ?, enabled = ?, revision = ?', $this->writes[0]['sql']);
        self::assertIsArray($this->writes[0]['bindings']);
        self::assertSame([0, 2], array_slice($this->writes[0]['bindings'], 1, 2));
        self::assertSame('cms.content-types.deleted', $this->audits[0]->code());
        self::assertSame('7', $this->audits[0]->entityKey());
    }

    /**
     * Obnova ponecha typ neaktivni a zachova puvodni identitu
     */
    public function testRestoreKeepsTypeInactiveAndAudits(): void
    {
        $result = (new SetDeletedAction($this->service(true, true, false, true), false))->execute(7, []);
        self::assertTrue($result->successful());
        self::assertSame('content-types.actions.restored', $result->messageKey());
        self::assertCount(1, $this->writes);
        self::assertIsArray($this->writes[0]['bindings']);
        self::assertSame([null, 0, 2], array_slice($this->writes[0]['bindings'], 0, 3));
        self::assertSame('cms.content-types.restored', $this->audits[0]->code());
    }

    /**
     * Opakovane smazani ani obnova existujiciho stavu nic nemeni
     */
    public function testRepeatedDeleteAndRestoreAreNoOps(): void
    {
        $this->service(true, true, false, true)->setDeleted(7, true);
        $this->service(true, true)->setDeleted(7, false);
        self::assertSame([], $this->writes);
        self::assertSame([], $this->audits);
    }

    /**
     * Mazani i obnoveni vyzaduji spravu definic i pri primem volani akce
     */
    public function testDeleteAndRestoreRequireManagement(): void
    {
        foreach ([true, false] as $deleted) {
            try {
                (new SetDeletedAction($this->service(false, true), $deleted))->execute(7, []);
                self::fail('Read-only user changed deletion state.');
            } catch (ModuleActionException $exception) {
                self::assertSame(403, $exception->status());
                self::assertSame([], $this->writes);
                self::assertSame([], $this->audits);
            }
        }
    }

    /**
     * Smazany typ nelze nacist do editoru, ulozit ani aktivovat
     */
    public function testDeletedTypeRejectsEditingAndActivation(): void
    {
        foreach (['load', 'save', 'enable'] as $operation) {
            $service = $this->service(true, true, false, true);
            try {
                match ($operation) {
                    'load' => $service->detail(7),
                    'save' => $service->save(7, $this->input(true)),
                    'enable' => $service->setEnabled(7, true),
                };
                self::fail('Deleted type was editable.');
            } catch (\Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException) {
                self::assertSame([], $this->writes);
                self::assertSame([], $this->audits);
            }
        }
    }

    /** @return array{name:string,code:string,enabled:int,revision:int,definition:string} */
    private function input(bool $existing = false): array
    {
        $field = ['key' => 'f8', 'name' => 'Field', 'code' => 'field', 'kind' => 'text'];
        $row = ['columns' => [['field' => 'f8', 'span' => 12]]];
        if ($existing) {
            $field['id'] = 8;
            $row['id'] = 9;
        }

        return ['name' => 'Type', 'code' => 'type', 'description' => 'Test content type', 'enabled' => 1, 'revision' => 1, 'definition' => json_encode(['fields' => [$field], 'rows' => [$row]], JSON_THROW_ON_ERROR)];
    }

    /**
     * Ohranicuje DB porty; produkcni validace, SQL a audit zustavaji soucasti testu
     */
    private function service(bool $allowed = true, bool $existing = false, bool $enabled = true, bool $deleted = false): DefinitionService
    {
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnArgument(0);
        $driver->method('escape')->willReturnArgument(0);
        $driver->method('query')->willReturnCallback(function (string $sql, array|false $bindings) use ($existing, $enabled, $deleted): DatabaseResultInterface {
            $rows = [];
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) {
                $this->writes[] = ['sql' => $sql, 'bindings' => $bindings];
            } elseif ($existing) {
                if (str_contains($sql, 'FROM cms_content_type')) {
                    $rows = [['id' => 7, 'code' => 'type', 'name' => 'Type', 'revision' => 1, 'enabled' => (int) $enabled, 'deleted_at' => $deleted ? '2026-11-06 12:00:00' : null]];
                    if ($deleted && str_contains($sql, 'deleted_at IS NULL')) {
                        $rows = [];
                    }
                } elseif (str_contains($sql, 'FROM cms_content_field ')) {
                    $rows = [['id' => 8, 'code' => 'field', 'kind' => 'text', 'localized' => 0, 'archived_at' => null]];
                } elseif (str_contains($sql, 'FROM cms_content_layout_row')) {
                    $rows = [['id' => 9]];
                }
            }
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);
            $result->method('row_array')->willReturn($rows[0] ?? null);
            return $result;
        });
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(static function (string $sql) use ($allowed): array {
            if (!str_contains($sql, 'SELECT DISTINCT p.code')) {
                return [];
            }
            $codes = ['cms.content-types.view'];
            if ($allowed) {
                $codes[] = 'cms.content-types.manage_definitions';
            }
            return array_map(static fn(string $code): array => ['code' => $code, 'module_code' => 'cms.content-types', 'name_key' => $code], $codes);
        });
        $connection->method('lastInsertId')->willReturn(41);
        $connection->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $database = new Database($connection, $driver);
        $principals = $this->createMock(CurrentPrincipalProviderInterface::class);
        $user = new AuthenticatedUser(1, 'admin@example.test');
        $principals->method('currentUser')->willReturn($user);
        $principals->method('currentPrincipal')->willReturn(new LocalAdminPrincipal($user));
        $audit = $this->createMock(AuditLogWriterInterface::class);
        $audit->method('record')->willReturnCallback(function (DomainEvent $event): void {
            $this->audits[] = $event;
        });

        return new DefinitionService(new DefinitionModel($database), $this->validation(), $this->types(), new TransactionalEventProcessor($database, $audit, new DomainEventDispatcher(new NullLogger())), new LocalActorGuard($principals), new AuthorizationService($principals, $database));
    }
}
