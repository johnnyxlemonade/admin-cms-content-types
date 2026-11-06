<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field;

use InvalidArgumentException;
use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;

/**
 * Explicitne registruje vestavene typy a sklada jejich spolecne atributy
 */
final readonly class FieldTypeRegistry
{
    /** @var array<string,FieldTypeInterface> */
    private array $types;

    /**
     * Prijme explicitni seznam typu a odmitne kolize kodu
     *
     * @param iterable<FieldTypeInterface> $types
     */
    public function __construct(iterable $types)
    {
        $registered = [];
        foreach ($types as $type) {
            if (isset($registered[$type->code()])) {
                throw new InvalidArgumentException('Duplicate field type code: ' . $type->code());
            }
            $registered[$type->code()] = $type;
        }
        $this->types = $registered;
    }

    /**
     * Vrati podporovane kody pro paletu i serverovy whitelist
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->types);
    }

    /**
     * Vyhleda explicitne registrovany typ
     */
    public function get(string $code): FieldTypeInterface
    {
        return $this->types[$code] ?? throw new InvalidArgumentException('Unsupported field kind: ' . $code);
    }

    /**
     * Vrati sloupce pravidel a defaultu pro nulovani nepouzitych hodnot
     *
     * @return array<string,null>
     */
    public function emptyValues(): array
    {
        $values = [];
        foreach ($this->types as $type) {
            $values['default_' . $type->storage()] = null;
            foreach ($type->ruleControls() as $name => $control) {
                if ($control !== null) {
                    $values[$name] = null;
                }
            }
        }
        return $values;
    }

    /**
     * Vrati sjednoceny seznam pravidel vcetne modalovych moznosti
     *
     * @return list<string>
     */
    public function ruleNames(): array
    {
        $rules = [];
        foreach ($this->types as $type) {
            $rules = [...$rules, ...array_keys($type->ruleControls())];
        }
        return array_values(array_unique($rules));
    }

    /**
     * Sestavi inspectory ze spolecnych vlastnosti a prispevku jednotlivych typu
     *
     * @return array<string,list<AdminEditorFieldDefinition>>
     */
    public function inspectors(): array
    {
        $inspectors = [];
        foreach ($this->types as $code => $type) {
            $inspectors[$code] = [
                Field::text('name', labelKey: 'content-types.fields.name')->required()->attributes(['maxlength' => '180']),
                Field::text('code', labelKey: 'content-types.fields.code')->help('', 'content-types.editor.code_help')->attributes(['maxlength' => '64']),
                Field::toggle('required', labelKey: 'content-types.fields.required'),
                Field::toggle('localized', labelKey: 'content-types.fields.localized'),
                Field::textarea('help', labelKey: 'content-types.fields.help'),
                ...array_values(array_filter($type->ruleControls())),
                $type->control('default_value', 'content-types.fields.default_value'),
                Field::text('css_class', labelKey: 'content-types.fields.css_class')->attributes(['maxlength' => '255']),
                Field::text('html_id', labelKey: 'content-types.fields.html_id')->help('', 'content-types.editor.html_id_help')->attributes(['maxlength' => '128']),
                Field::select('span', labelKey: 'content-types.fields.width')->options(array_combine(range(1, 12), array_map(static fn(int $span): string => $span . '/12', range(1, 12)))),
            ];
            $inspectors[$code] = array_map(
                static fn(AdminEditorFieldDefinition $field): AdminEditorFieldDefinition => $field->helpKey($field->helpTranslationKey() ?? 'content-types.help.' . $field->name()),
                $inspectors[$code],
            );
        }
        return $inspectors;
    }

    /**
     * Sestavi control obsahove hodnoty se spolecnymi atributy a pravidly definice
     *
     * @param array<string,mixed> $definition
     */
    public function contentField(array $definition): \Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition
    {
        $type = $this->get((string) $definition['kind']);
        $field = $type->control('field_' . $definition['id'])
            ->label((string) $definition['name'])
            ->help((string) ($definition['help'] ?? ''))
            ->required((int) $definition['required'] === 1);
        if ($field instanceof \Lemonade\Admin\Editor\AdminEditor\Field\SelectField) {
            if ($type->storage() === 'option') {
                $options = ['' => ''];
                foreach ($definition['options'] as $option) {
                    if ($option['archived_at'] === null) {
                        $options[(string) $option['code']] = (string) $option['name'];
                    }
                }
                $field = $field->options($options)->optionLabelKeys(['' => 'records.fields.empty_value']);
            } else {
                $field = $field->optionLabelKeys(['' => 'records.fields.empty_value', '0' => 'content-types.editor.no', '1' => 'content-types.editor.yes']);
            }
        }
        $attributes = $field->inputAttributes();
        $attributes['id'] = (string) ($definition['html_id'] ?? 'field-' . $definition['code']);
        if (($definition['css_class'] ?? '') !== '') {
            $attributes['class'] = (string) $definition['css_class'];
        }
        $projection = $type->editorValues($definition) + $definition;
        foreach (['min_length' => 'minlength', 'max_length' => 'maxlength', 'min_number' => 'min', 'max_number' => 'max', 'min_date' => 'min', 'max_date' => 'max'] as $rule => $attribute) {
            if (isset($type->ruleControls()[$rule]) && ($projection[$rule] ?? '') !== '' && $projection[$rule] !== null) {
                $attributes[$attribute] = (string) $projection[$rule];
            }
        }
        if (isset($type->ruleControls()['decimal_places'])) {
            $places = (int) ($definition['decimal_places'] ?? 2);
            $attributes['step'] = $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
        }
        return $field->attributes($attributes);
    }

    /**
     * Vykresli nahled stejnymi canonical controls
     *
     * @return array<string,AdminEditorFieldDefinition>
     */
    public function previews(): array
    {
        return array_map(static fn(FieldTypeInterface $type): AdminEditorFieldDefinition => $type->control('preview')->disabled(), $this->types);
    }

    /**
     * Doplnuje projekci konkretniho typu o spolecnou identitu a aktivni moznosti
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public function editorField(array $field): array
    {
        $field = $this->get((string) $field['kind'])->editorValues($field) + $field;
        $field['id'] = (int) $field['id'];
        $field['key'] = 'f' . $field['id'];
        $field['archived'] = $field['archived_at'] !== null;
        $field['options'] = array_map(static fn(array $option): array => ['id' => (int) $option['id'], 'code' => (string) $option['code'], 'name' => (string) $option['name']], array_values(array_filter($field['options'], static fn(array $option): bool => $option['archived_at'] === null)));
        return $field;
    }
}
