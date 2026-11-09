<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\PermissionSyncService;
use Lemonade\Admin\Editor\Exception\EditorCapabilityException;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\TransactionalEventCollector;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Models\DefinitionModel;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Framework\Database\Exception\DatabaseException;
use Lemonade\Framework\Http\HttpStatus;

final readonly class DefinitionService
{
    public function __construct(
        private DefinitionModel $model,
        private DefinitionValidation $validation,
        private FieldTypeRegistry $types,
        private TransactionalEventProcessor $events,
        private LocalActorGuard $actors,
        private AuthorizationService $authorization,
        private ?PermissionSyncService $permissions = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function detail(int $id): array
    {
        $type = $this->model->type($id) ?? throw new EditorEntityNotFoundException('Content type not found.');
        $fields = array_map($this->types->editorField(...), $this->model->fields($id));
        $columnsByRow = [];
        foreach ($fields as $field) {
            if ($field['archived'] === false) {
                $columnsByRow[(int) ($field['row_id'] ?? 0)][] = ['field' => $field['key'], 'span' => (int) $field['span']];
            }
        }
        $rows = [];
        foreach ($this->model->rows($id) as $row) {
            $columns = $columnsByRow[(int) $row['id']] ?? [];
            if ($columns !== []) {
                $rows[] = ['id' => (int) $row['id'], 'columns' => $columns];
            }
        }
        $type['definition'] = json_encode(['fields' => $fields, 'rows' => $rows], JSON_THROW_ON_ERROR);

        return $type;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function save(?int $id, array $input): int
    {
        $this->requireManagement();
        $actor = $this->actors->requireLocalUser();
        $values = $this->validation->type($input);
        try {
            return $this->events->execute(AuditActor::user($actor->id()), function (TransactionalEventCollector $events) use ($id, $input, $values): int {
                $existing = $id === null ? null : ($this->model->type($id, true) ?? throw new EditorEntityNotFoundException('Content type not found.'));
                if ($existing !== null) {
                    if ((string) $values['code'] !== (string) $existing['code']) {
                        $this->validation->fail('code', 'immutable');
                    }
                    if ((string) ($input['revision'] ?? '') !== (string) $existing['revision']) {
                        $this->validation->fail('_form', 'stale');
                    }
                } elseif ($this->model->codeExists((string) $values['code'])) {
                    $this->validation->fail('code', 'duplicate');
                }
                $definition = $this->validation->definition($input['definition'] ?? null);
                $storedFields = $id === null ? [] : $this->model->fields($id);
                $storedRows = $id === null ? [] : $this->model->rows($id);
                $prepared = $this->validation->prepare($definition, $storedFields, $storedRows);
                $storedById = array_column($storedFields, null, 'id');
                foreach ($prepared as $field) {
                    if ($field['id'] !== null
                        && (int) $storedById[$field['id']]['localized'] !== (int) $field['values']['localized']
                        && $this->model->fieldHasValues($field['id'])) {
                        $this->validation->fail('definition', 'localized_in_use');
                    }
                }
                $typeId = $id ?? $this->model->createType($values);
                if ($existing === null && $this->permissions !== null) {
                    $module = new RecordModule($typeId, (string) $values['name'], (string) $values['code'], (int) $values['enabled'] === 1);
                    $this->permissions->syncDefinitions($module->permissionDefinitions());
                }
                $rowIds = [];
                foreach ($definition['rows'] as $position => $row) {
                    $rowIds[$position] = $this->model->saveRow($typeId, isset($row['id']) ? (int) $row['id'] : null, $position);
                }
                foreach ($prepared as $field) {
                    $attributes = $field['values'];
                    $attributes['row_id'] = $field['row'] === null ? null : $rowIds[$field['row']];
                    $fieldId = $this->model->saveField($typeId, $field['id'], $attributes);
                    $this->model->saveOptions($fieldId, $field['options']);
                }
                if ($existing !== null) {
                    $this->model->updateType($typeId, $values, (int) $existing['revision']);
                }
                $events->record(new DomainEvent(
                    ModuleManifest::CODE . ($existing === null ? '.created' : '.updated'),
                    ModuleManifest::CODE,
                    'cms_content_type',
                    (string) $typeId,
                    ['code' => $values['code'], 'field_count' => count($prepared)],
                ));

                return $typeId;
            });
        } catch (DatabaseException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'uq_cms_content_type_code')) {
                $this->validation->fail('code', 'duplicate');
            }
            throw $exception;
        }
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->requireManagement();
        $actor = $this->actors->requireLocalUser();
        $this->events->execute(AuditActor::user($actor->id()), function (TransactionalEventCollector $events) use ($id, $enabled): void {
            $type = $this->model->type($id, true) ?? throw new EditorEntityNotFoundException('Content type not found.');
            if (((int) $type['enabled'] === 1) === $enabled) {
                $events->noOp();

                return;
            }
            $this->model->updateType($id, ['enabled' => (int) $enabled], (int) $type['revision']);
            $events->record(new DomainEvent(
                ModuleManifest::CODE . ($enabled ? '.enabled' : '.disabled'),
                ModuleManifest::CODE,
                'cms_content_type',
                (string) $id,
                ['code' => $type['code']],
            ));
        });
    }

    /**
     * Restoring a type leaves it disabled; child definitions are preserved.
     */
    public function setDeleted(int $id, bool $deleted): void
    {
        $this->requireManagement();
        $actor = $this->actors->requireLocalUser();
        $this->events->execute(AuditActor::user($actor->id()), function (TransactionalEventCollector $events) use ($id, $deleted): void {
            $type = $this->model->type($id, lock: true, includeDeleted: true) ?? throw new EditorEntityNotFoundException('Content type not found.');
            if (($type['deleted_at'] !== null) === $deleted) {
                $events->noOp();

                return;
            }
            $this->model->updateType($id, [
                'deleted_at' => $deleted ? date('Y-m-d H:i:s') : null,
                'enabled' => 0,
            ], (int) $type['revision']);
            $events->record(new DomainEvent(
                ModuleManifest::CODE . ($deleted ? '.deleted' : '.restored'),
                ModuleManifest::CODE,
                'cms_content_type',
                (string) $id,
                ['code' => $type['code']],
            ));
        });
    }

    private function requireManagement(): void
    {
        if (!$this->authorization->hasPermission(ModuleManifest::CODE . '.manage_definitions')) {
            throw new EditorCapabilityException(HttpStatus::FORBIDDEN, AdminErrorCode::PERMISSION_DENIED, 'Permission denied.');
        }
    }
}
