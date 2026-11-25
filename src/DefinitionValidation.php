<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes;

use JsonException;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use Lemonade\Cms\ContentTypes\Field\FieldValidation;
use Lemonade\Framework\Validation\ValidationSchema;

final readonly class DefinitionValidation
{
    public function __construct(private FieldTypeRegistry $types, private FieldValidation $values) {}

    /**
     * @param array<string,mixed> $input
     * @return array{name:string,code:string}
     */
    public function identity(array $input): array
    {
        return $this->values->identity($input);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,int|string|null>
     */
    public function type(array $input): array
    {
        $description = $this->values->text($input, 'description');
        $this->values->check(
            ['description' => $description],
            ValidationSchema::create()->field('description')->required()->maxLength(10000)->end(),
        );

        return $this->identity($input) + [
            'description' => $description,
            'enabled' => $this->values->boolean($input, 'enabled'),
            'thumbnail_enabled' => $this->values->boolean($input, 'thumbnail_enabled'),
            'gallery_enabled' => $this->values->boolean($input, 'gallery_enabled'),
            'attachments_enabled' => $this->values->boolean($input, 'attachments_enabled'),
            'tags_enabled' => $this->values->boolean($input, 'tags_enabled'),
        ];
    }

    /**
     * Validates localized canonical path prefixes submitted by the editor
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public function routePrefixes(array $input): array
    {
        $prefixes = [];
        foreach ($input as $field => $value) {
            if (!str_starts_with($field, 'route_prefix_')) {
                continue;
            }
            $locale = substr($field, strlen('route_prefix_'));
            if (preg_match('/^[a-z][a-z0-9-]{1,9}$/D', $locale) !== 1) {
                $this->fail($field, 'invalid');
            }
            $pathPrefix = trim(trim($this->values->text([$field => $value], $field)), '/');
            if ($pathPrefix !== '' && (
                strlen($pathPrefix) > 180
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/D', $pathPrefix) !== 1
            )) {
                $this->fail($field, 'route_prefix');
            }
            $prefixes[$locale] = $pathPrefix;
        }

        return $prefixes;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function field(array $input): array
    {
        $identity = $this->identity($input);
        $kind = $this->values->text($input, 'kind');
        $this->values->check(['kind' => $kind, 'sort_order' => $this->values->text($input, 'sort_order')], ValidationSchema::create()
            ->field('kind')->required()->inList($this->types->codes())
            ->field('sort_order')->required()->integer()->greaterThanOrEqualTo(0)->lessThanOrEqualTo(1000000)->end());
        $type = $this->types->get($kind);
        foreach (array_diff($this->types->ruleNames(), array_keys($type->ruleControls())) as $rule) {
            if (isset($input[$rule]) && !in_array($input[$rule], ['', []], true)) {
                $this->fail($rule, 'irrelevant');
            }
        }
        $definition = $type->validate($input, $this->values);
        $definition['values'] = $identity + [
            'kind' => $kind,
            'required' => $this->values->boolean($input, 'required'),
            'localized' => $this->values->boolean($input, 'localized'),
            'sort_order' => (int) $input['sort_order'],
            'help' => $this->values->text($input, 'help'),
        ] + $definition['values'] + $this->types->emptyValues();
        $definition['values'] += $this->presentation($input);
        return $definition;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{css_class:string|null,html_id:string|null}
     */
    private function presentation(array $input): array
    {
        $classes = preg_replace('/\s+/u', ' ', $this->values->text($input, 'css_class')) ?? '';
        $id = $this->values->text($input, 'html_id');
        if ($classes !== '' && (strlen($classes) > 255 || preg_match('/^-?[_a-zA-Z][_a-zA-Z0-9-]*(?: -?[_a-zA-Z][_a-zA-Z0-9-]*)*$/D', $classes) !== 1)) {
            $this->fail('css_class', 'css_class');
        }
        if ($id !== '' && (strlen($id) > 128 || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $id) !== 1)) {
            $this->fail('html_id', 'html_id');
        }
        return ['css_class' => $classes === '' ? null : $classes, 'html_id' => $id === '' ? null : $id];
    }

    public function fail(string $field, string $reason): never
    {
        $this->values->fail($field, $reason);
    }

    /**
     * @return array{fields:list<array<string,mixed>>,rows:list<array<string,mixed>>}
     */
    public function definition(mixed $payload): array
    {
        if (!is_string($payload) || strlen($payload) > 1000000) {
            $this->fail('definition', 'invalid');
        }
        try {
            $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('definition', 'invalid');
        }
        if (!is_array($data)) {
            $this->fail('definition', 'invalid');
        }
        foreach (['fields', 'rows'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key]) || count($data[$key]) > 100) {
                $this->fail('definition', 'invalid');
            }
            foreach ($data[$key] as $item) {
                if (!is_array($item)) {
                    $this->fail('definition', 'invalid');
                }
            }
        }

        return ['fields' => $data['fields'], 'rows' => $data['rows']];
    }

    /**
     * @param array{fields:list<array<string,mixed>>,rows:list<array<string,mixed>>} $definition
     * @param list<array<string,mixed>> $storedFields
     * @param list<array<string,mixed>> $storedRows
     * @return list<array{id:int|null,values:array<string,int|string|null>,options:list<array{code:string,name:string}>,children:list<array{id:int|null,code:string,name:string,kind:string,required:int,localized:int}>,row:int|null}>
     */
    public function prepare(array $definition, array $storedFields, array $storedRows): array
    {
        $existing = array_column($storedFields, null, 'id');
        $seenIds = [];
        $keys = [];
        $codes = [];
        $htmlIds = [];
        $prepared = [];
        foreach ($definition['fields'] as $index => $input) {
            $key = $input['key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $key) !== 1 || isset($keys[$key])) {
                $this->fail('definition', 'invalid');
            }
            $id = $input['id'] ?? null;
            if ($id !== null && (!is_int($id) || !isset($existing[$id]) || isset($seenIds[$id]))) {
                $this->fail('definition', 'invalid');
            }
            if ($id !== null) {
                $seenIds[$id] = true;
                foreach (['code', 'kind'] as $immutable) {
                    if (($input[$immutable] ?? null) !== $existing[$id][$immutable]) {
                        $this->fail('definition', 'immutable');
                    }
                }
                $this->validateOptionIdentity($input, $existing[$id]);
            }
            $input['sort_order'] = $index;
            try {
                $field = $this->field($input);
            } catch (DefinitionValidationException $exception) {
                $label = is_string($input['name'] ?? null) ? $input['name'] : $key;
                throw new DefinitionValidationException(['definition' => $label . ': ' . implode(' ', $exception->errors())]);
            }
            $field['children'] = $field['values']['kind'] === 'repeater'
                ? $this->repeaterFields($input['children'] ?? null, $id === null ? [] : $existing[$id]['children'])
                : [];
            $code = (string) $field['values']['code'];
            if (isset($codes[$code])) {
                $this->fail('definition', 'duplicate');
            }
            $codes[$code] = true;
            $keys[$key] = $index;
            if (isset($input['archived']) && !is_bool($input['archived'])) {
                $this->fail('definition', 'invalid');
            }
            $archived = ($input['archived'] ?? false) === true;
            $existingArchivedAt = $id === null ? null : ($existing[$id]['archived_at'] ?? null);
            $field['values']['archived_at'] = $archived ? (string) ($existingArchivedAt ?? date('Y-m-d H:i:s')) : null;
            $field['values']['span'] = 12;
            $htmlId = $field['values']['html_id'] ?? 'field-' . $code;
            if (!$archived && isset($htmlIds[$htmlId])) {
                $this->fail('html_id', 'duplicate_html_id');
            }
            if (!$archived) {
                $htmlIds[$htmlId] = true;
            }
            $prepared[] = $field + ['id' => $id, 'row' => null];
        }
        if (count($seenIds) !== count($existing)) {
            $this->fail('definition', 'incomplete');
        }
        return $this->layout(
            rows: $definition['rows'],
            storedRows: $storedRows,
            keys: $keys,
            prepared: $prepared,
        );
    }

    /**
     * Validates the intentionally small child schema supported by repeaters
     *
     * @param list<array<string,mixed>> $stored
     * @return list<array{id:int|null,code:string,name:string,kind:string,required:int,localized:int}>
     */
    private function repeaterFields(mixed $input, array $stored): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) > 20) {
            $this->fail('definition', 'repeater_fields');
        }
        $storedById = array_column($stored, null, 'id');
        $seenIds = [];
        $codes = [];
        $children = [];
        foreach ($input as $child) {
            if (!is_array($child)) {
                $this->fail('definition', 'repeater_fields');
            }
            try {
                $identity = $this->identity($child);
            } catch (DefinitionValidationException) {
                $this->fail('definition', 'repeater_fields');
            }
            $id = $child['id'] ?? null;
            if ($id !== null && (!is_int($id) || !isset($storedById[$id]) || isset($seenIds[$id]))) {
                $this->fail('definition', 'immutable');
            }
            $kind = $this->values->text($child, 'kind');
            if (!in_array($kind, ['text', 'textarea', 'richtext'], true)) {
                $this->fail('definition', 'repeater_fields');
            }
            if ($id !== null) {
                $seenIds[$id] = true;
                if ($identity['code'] !== $storedById[$id]['code'] || $kind !== $storedById[$id]['kind']) {
                    $this->fail('definition', 'immutable');
                }
            }
            if (isset($codes[$identity['code']])) {
                $this->fail('definition', 'duplicate');
            }
            $codes[$identity['code']] = true;
            $children[] = [
                'id' => $id,
                'code' => $identity['code'],
                'name' => $identity['name'],
                'kind' => $kind,
                'required' => $this->values->boolean($child, 'required'),
                'localized' => $this->values->boolean($child, 'localized'),
            ];
        }

        return $children;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $existing
     */
    private function validateOptionIdentity(array $input, array $existing): void
    {
        $optionsById = array_column($existing['options'], null, 'id');
        $options = is_array($input['options'] ?? null) ? $input['options'] : [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                $this->fail('definition', 'option');
            }
            if (isset($option['id']) && (
                !is_int($option['id'])
                || !isset($optionsById[$option['id']])
                || $optionsById[$option['id']]['code'] !== ($option['code'] ?? null)
            )) {
                $this->fail('definition', 'immutable');
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $storedRows
     * @param array<string,int> $keys
     * @param list<array{id:int|null,values:array<string,int|string|null>,options:list<array{code:string,name:string}>,children:list<array{id:int|null,code:string,name:string,kind:string,required:int,localized:int}>,row:int|null}> $prepared
     * @return list<array{id:int|null,values:array<string,int|string|null>,options:list<array{code:string,name:string}>,children:list<array{id:int|null,code:string,name:string,kind:string,required:int,localized:int}>,row:int|null}>
     */
    private function layout(array $rows, array $storedRows, array $keys, array $prepared): array
    {
        $rowIds = array_map(intval(...), array_column($storedRows, 'id'));
        $seenRows = [];
        $placed = [];
        foreach ($rows as $position => $row) {
            if (isset($row['id'])) {
                if (!is_int($row['id']) || !in_array($row['id'], $rowIds, true) || isset($seenRows[$row['id']])) {
                    $this->fail('definition', 'invalid');
                }
                $seenRows[$row['id']] = true;
            }
            if (!isset($row['columns']) || !is_array($row['columns']) || !array_is_list($row['columns']) || $row['columns'] === []) {
                $this->fail('definition', 'layout');
            }
            $rowWidth = 0;
            foreach ($row['columns'] as $column) {
                if (!is_array($column)
                    || !is_string($column['field'] ?? null)
                    || !isset($keys[$column['field']])
                    || isset($placed[$column['field']])
                    || !is_int($column['span'] ?? null)
                    || $column['span'] < 1
                    || $column['span'] > 12) {
                    $this->fail('definition', 'layout');
                }
                $index = $keys[$column['field']];
                if ($prepared[$index]['values']['archived_at'] !== null) {
                    $this->fail('definition', 'layout');
                }
                $placed[$column['field']] = true;
                $prepared[$index]['row'] = $position;
                $prepared[$index]['values']['span'] = $column['span'];
                $rowWidth += $column['span'];
            }
            if ($rowWidth > 12) {
                $this->fail('definition', 'layout');
            }
        }
        foreach ($prepared as $field) {
            if ($field['values']['archived_at'] === null && $field['row'] === null) {
                $this->fail('definition', 'layout');
            }
        }

        return $prepared;
    }
}
