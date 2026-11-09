<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Editor;

use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDefinition;
use Lemonade\Admin\Editor\EditorFieldDefinition;
use Lemonade\Admin\Editor\EditorSaveResult;
use Lemonade\Admin\Editor\Exception\EditorValidationException;
use Lemonade\Cms\ContentTypes\DefinitionService;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Pripojuje editor definic ke sdilenemu save transportu a zamkum
 */
final readonly class ContentTypesEditor implements EditorProviderInterface
{
    /**
     * Nastavuje auditovanou sluzbu a generovani navratove URL
     */
    public function __construct(private DefinitionService $definitions, private UrlGenerator $urls) {}

    public function editorDefinition(): EditorDefinition
    {
        $permission = ModuleManifest::CODE . '.manage_definitions';

        return new EditorDefinition(
            loadPermission: $permission,
            savePermission: $permission,
            createPermission: $permission,
            fields: array_map(static fn(string $name): EditorFieldDefinition => new EditorFieldDefinition($name, 'text', false, null), ['name', 'code', 'description', 'enabled', 'gallery_enabled', 'attachments_enabled', 'revision', 'definition']),
        );
    }

    public function createData(): array
    {
        return ['type' => ['name' => '', 'code' => '', 'description' => '', 'enabled' => 1, 'gallery_enabled' => 0, 'attachments_enabled' => 0, 'revision' => 0, 'definition' => '{"fields":[],"rows":[]}']];
    }

    public function updateData(int $id): array
    {
        return ['type' => $this->definitions->detail($id)];
    }

    public function createValidationSchema(): ValidationSchema
    {
        return ValidationSchema::create()
            ->field('name')->required()->maxLength(180)
            ->field('code')->maxLength(64)
            ->field('description')->required()->maxLength(10000)
            ->field('enabled')->inList(['0', '1'])
            ->field('gallery_enabled')->inList(['0', '1'])
            ->field('attachments_enabled')->inList(['0', '1'])
            ->field('revision')->integer()
            ->field('definition')->required()->maxLength(1000000)->end();
    }

    public function updateValidationSchema(int $id): ValidationSchema
    {
        return $this->createValidationSchema();
    }

    public function create(array $data): EditorSaveResult
    {
        return $this->save(null, $data);
    }

    public function update(int $id, array $data): EditorSaveResult
    {
        return $this->save($id, $data);
    }

    /**
     * Prevede domenove chyby do standardniho editoroveho vysledku
     *
     * @param array<string,mixed> $data
     */
    private function save(?int $id, array $data): EditorSaveResult
    {
        try {
            $id = $this->definitions->save($id, $data);
        } catch (DefinitionValidationException $exception) {
            throw new EditorValidationException(implode(' ', $exception->errors()), previous: $exception);
        }
        $url = $this->urls->route('admin.cms.module.edit', ['module' => 'content-types', 'id' => $id]);

        return new EditorSaveResult(['id' => $id, 'editUrl' => $url, 'successUrl' => $url], 'content-types.editor.saved');
    }
}
