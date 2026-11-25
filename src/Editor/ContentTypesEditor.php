<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Editor;

use Lemonade\Admin\Editor\Contract\EditorProviderInterface;
use Lemonade\Admin\Editor\EditorDefinition;
use Lemonade\Admin\Editor\EditorFieldDefinition;
use Lemonade\Admin\Editor\EditorSaveResult;
use Lemonade\Admin\Editor\Exception\EditorValidationException;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\ContentTypes\DefinitionService;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\ModuleManifest;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Validation\ValidationSchema;

final readonly class ContentTypesEditor implements EditorProviderInterface
{
    public function __construct(
        private DefinitionService $definitions,
        private UrlGenerator $urls,
        private LanguageRegistry $languages,
    ) {}

    public function editorDefinition(): EditorDefinition
    {
        $permission = ModuleManifest::CODE . '.manage_definitions';

        return new EditorDefinition(
            loadPermission: $permission,
            savePermission: $permission,
            createPermission: $permission,
            fields: array_map(
                static fn(string $name): EditorFieldDefinition => new EditorFieldDefinition(
                    name: $name,
                    type: 'text',
                    readOnly: false,
                    permission: null,
                ),
                [
                    'name',
                    'code',
                    'description',
                    'sort_order',
                    'enabled',
                    'thumbnail_enabled',
                    'gallery_enabled',
                    'attachments_enabled',
                    'tags_enabled',
                    'revision',
                    'definition',
                    ...$this->routePrefixFieldNames(),
                ],
            ),
        );
    }

    public function createData(): array
    {
        $type = [
            'name' => '',
            'code' => '',
            'description' => '',
            'sort_order' => $this->definitions->nextSortOrder(),
            'enabled' => 1,
            'thumbnail_enabled' => 0,
            'gallery_enabled' => 0,
            'attachments_enabled' => 0,
            'tags_enabled' => 0,
            'revision' => 0,
            'definition' => '{"fields":[],"rows":[]}',
        ];
        foreach ($this->routePrefixFieldNames() as $field) {
            $type[$field] = '';
        }

        return ['type' => $type];
    }

    public function updateData(int $id): array
    {
        return ['type' => $this->definitions->detail($id)];
    }

    public function createValidationSchema(): ValidationSchema
    {
        $schema = ValidationSchema::create()
            ->field('name')->required()->maxLength(180)
            ->field('code')->maxLength(64)
            ->field('description')->required()->maxLength(10000)
            ->field('sort_order')->required()->integer()->greaterThanOrEqualTo(0)->lessThanOrEqualTo(1000000)
            ->field('enabled')->inList(['0', '1'])
            ->field('thumbnail_enabled')->inList(['0', '1'])
            ->field('gallery_enabled')->inList(['0', '1'])
            ->field('attachments_enabled')->inList(['0', '1'])
            ->field('tags_enabled')->inList(['0', '1'])
            ->field('revision')->integer()
            ->field('definition')->required()->maxLength(1000000);
        foreach ($this->routePrefixFieldNames() as $field) {
            $schema->field($field)->maxLength(180);
        }

        return $schema->end();
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

    /**
     * Returns transport field names for currently enabled content locales
     *
     * @return list<string>
     */
    private function routePrefixFieldNames(): array
    {
        return array_map(
            static fn(array $locale): string => 'route_prefix_' . $locale['code'],
            $this->languages->enabledLocales(),
        );
    }
}
