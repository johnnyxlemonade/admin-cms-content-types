<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Models;

use Lemonade\Framework\Database\Database;

/**
 * Nacita a uklada definice typu, poli, moznosti a rozvrzeni
 */
final readonly class DefinitionModel
{
    /**
     * Nastavuje databazi pro spolecnou transakci definice
     */
    public function __construct(private Database $database) {}

    /**
     * Nacte nesmazany typ; lifecycle muze pod zamkem zahrnout i smazane typy
     *
     * @return array<string,mixed>|null
     */
    public function type(int $id, bool $lock = false, bool $includeDeleted = false): ?array
    {
        $query = $this->database->table('cms_content_type')->where('id', $id);
        if (!$includeDeleted) {
            $query = $query->where('deleted_at', null);
        }

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Overi obsazenost stabilniho kodu typu
     */
    public function codeExists(string $code): bool
    {
        return $this->database->table('cms_content_type')->where('code', $code)->exists();
    }

    /**
     * Nacte vsechna pole vcetne archivovanych a jejich aktivni moznosti
     *
     * @return list<array<string,mixed>>
     */
    public function fields(int $id, bool $lock = false): array
    {
        $query = $this->database->table('cms_content_field')->where('type_id', $id)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC');
        $fields = ($lock ? $query->lockForUpdate() : $query)->getArray();
        foreach ($fields as &$field) {
            $field['options'] = $this->options((int) $field['id'], $lock);
        }
        unset($field);

        return $fields;
    }

    /**
     * Nacte moznosti vcetne archivovanych pro ochranu jejich identity
     *
     * @return list<array<string,mixed>>
     */
    public function options(int $fieldId, bool $lock = false): array
    {
        $query = $this->database->table('cms_content_field_option')->where('field_id', $fieldId)->orderBy('sort_order', 'ASC');
        return ($lock ? $query->lockForUpdate() : $query)->getArray();
    }

    /**
     * Nacte radky layoutu konkretniho typu
     *
     * @return list<array<string,mixed>>
     */
    public function rows(int $typeId): array
    {
        return $this->database->table('cms_content_layout_row')->where('type_id', $typeId)->orderBy('sort_order', 'ASC')->getArray();
    }

    /**
     * Vytvori typ s validovanymi atributy
     *
     * @param array<string,int|string|null> $values
     */
    public function createType(array $values): int
    {
        $this->database->table('cms_content_type')->insert($values + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * Ulozi atributy typu a zvysi revizi cele definice
     *
     * @param array<string,int|string|null> $values
     */
    public function updateType(int $id, array $values, int $revision): void
    {
        $this->database->table('cms_content_type')->where('id', $id)->set($values + ['revision' => $revision + 1, 'updated_at' => date('Y-m-d H:i:s')])->update();
    }

    /**
     * Vytvori nebo posune existujici radek layoutu
     */
    public function saveRow(int $typeId, ?int $id, int $position): int
    {
        if ($id !== null) {
            $this->database->table('cms_content_layout_row')->where('id', $id)->where('type_id', $typeId)->set(['sort_order' => $position])->update();

            return $id;
        }
        $this->database->table('cms_content_layout_row')->insert(['type_id' => $typeId, 'sort_order' => $position]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * Ulozi pole bez zmeny jeho stabilni identity
     *
     * @param array<string,int|string|null> $values
     */
    public function saveField(int $typeId, ?int $id, array $values): int
    {
        $values['updated_at'] = date('Y-m-d H:i:s');
        if ($id !== null) {
            $this->database->table('cms_content_field')->where('id', $id)->where('type_id', $typeId)->set($values)->update();

            return $id;
        }
        $this->database->table('cms_content_field')->insert($values + ['type_id' => $typeId, 'created_at' => $values['updated_at']]);

        return (int) $this->database->lastInsertId();
    }

    /**
     * Aktualizuje moznosti podle stabilniho kodu a chybejici archivuje
     *
     * @param list<array{code:string,name:string}> $options
     */
    public function saveOptions(int $fieldId, array $options): void
    {
        $existing = array_column($this->options($fieldId), null, 'code');
        $now = date('Y-m-d H:i:s');
        $this->database->table('cms_content_field_option')->where('field_id', $fieldId)->where('archived_at', null)->set(['archived_at' => $now, 'updated_at' => $now])->update();
        foreach ($options as $position => $option) {
            $values = $option + ['sort_order' => $position, 'archived_at' => null, 'updated_at' => $now];
            if (isset($existing[$option['code']])) {
                $this->database->table('cms_content_field_option')->where('id', (int) $existing[$option['code']]['id'])->set($values)->update();
            } else {
                $this->database->table('cms_content_field_option')->insert($values + ['field_id' => $fieldId, 'created_at' => $now]);
            }
        }
    }

    /**
     * Chrani scope definice pred skrytim jiz ulozenych hodnot
     */
    public function fieldHasValues(int $fieldId): bool
    {
        if (!$this->database->tableExists('cms_content_record_value')) {
            return false;
        }
        foreach (['cms_content_record_value', 'cms_content_record_translation_value'] as $table) {
            if ($this->database->table($table)->where('field_id', $fieldId)->exists()) {
                return true;
            }
        }
        return false;
    }
}
