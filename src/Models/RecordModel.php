<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Record\RecordModule;
use Lemonade\Framework\Database\Database;

final readonly class RecordModel
{
    public function __construct(private Database $database, private FieldTypeRegistry $types) {}

    /**
     * Disabled types remain registered so existing content stays manageable.
     *
     * @return list<RecordModule>
     */
    public function modules(): array
    {
        if (!$this->database->tableExists('cms_content_record')) {
            return [];
        }
        return array_map(static fn(array $row): RecordModule => new RecordModule(
            id: (int) $row['id'],
            name: (string) $row['name'],
            typeCode: (string) $row['code'],
            enabled: (int) $row['enabled'] === 1 && $row['deleted_at'] === null,
            description: $row['description'] === null ? null : (string) $row['description'],
            galleryEnabled: (int) ($row['gallery_enabled'] ?? 0) === 1,
            attachmentsEnabled: (int) ($row['attachments_enabled'] ?? 0) === 1,
            thumbnailEnabled: (int) ($row['thumbnail_enabled'] ?? 0) === 1,
        ), $this->database->table('cms_content_type')->orderBy('id')->getArray());
    }

    public function translationId(int $id, string $locale): ?int
    {
        $row = $this->database->table('cms_content_record_translation')->select(['id'])->where('record_id', $id)->where('locale', $locale)->first();
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * A missing row differs from an explicitly empty value when applying defaults.
     *
     * @param list<array<string,mixed>> $fields
     * @return array<int,int|string|null>
     */
    public function values(int $typeId, int $id, string $locale, array $fields): array
    {
        $rows = $this->database->table('cms_content_record_value')->where('type_id', $typeId)->where('record_id', $id)->getArray();
        $translationId = $this->translationId($id, $locale);
        if ($translationId !== null) {
            $rows = [...$rows, ...$this->database->table('cms_content_record_translation_value')->where('type_id', $typeId)->where('translation_id', $translationId)->getArray()];
        }
        $definitions = array_column($fields, null, 'id');
        $values = [];
        foreach ($rows as $row) {
            $field = $definitions[$row['field_id']] ?? null;
            if ($field === null) {
                continue;
            }
            $storage = $this->types->get((string) $field['kind'])->storage();
            $value = $row['value_' . $storage];
            if ($storage === 'option' && $value !== null) {
                $options = array_column($field['options'], 'code', 'id');
                $value = $options[$value] ?? null;
            }
            $values[(int) $row['field_id']] = $value === null ? null : (string) $value;
        }
        return $values;
    }

    /**
     * The caller must hold the CMS root lock.
     *
     * @param list<array<string,mixed>> $fields
     * @param array<int,int|string|null> $values
     */
    public function save(int $typeId, int $id, string $locale, array $fields, array $values): void
    {
        $translationId = $this->translationId($id, $locale);
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            if (!array_key_exists($fieldId, $values)) {
                continue;
            }
            $localized = (int) $field['localized'] === 1;
            $table = $localized ? 'cms_content_record_translation_value' : 'cms_content_record_value';
            $identity = ['record_id' => $id, 'type_id' => $typeId, 'field_id' => $fieldId];
            if ($localized) {
                $identity['translation_id'] = $translationId;
            }
            $storage = $this->types->get((string) $field['kind'])->storage();
            $value = $values[$fieldId];
            if ($storage === 'option' && $value !== null) {
                $options = array_column($field['options'], 'id', 'code');
                $value = (int) $options[$value];
            }
            $query = $this->database->table($table)->where('record_id', $id)->where('field_id', $fieldId);
            if ($localized) {
                $query = $query->where('translation_id', $translationId);
            }
            $columns = ['value_' . $storage => $value];
            if ($query->exists()) {
                $query->set($columns)->update();
            } else {
                $this->database->table($table)->insert($identity + $columns);
            }
        }
    }

    /**
     * Shared required fields must stay valid while any translation is published.
     */
    public function hasOtherPublishedLocale(int $id, string $locale): bool
    {
        return $this->database->table('cms_content_record_translation')->where('record_id', $id)->where('publication_state', CmsPublicationState::Published->value)->whereRaw('locale <> ?', [$locale])->exists();
    }
}
