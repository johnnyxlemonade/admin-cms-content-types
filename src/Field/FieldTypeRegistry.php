<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field;

use InvalidArgumentException;
use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorInputFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Field\SelectField;
use Lemonade\Admin\Presentation\AdminFileUsageDefinition;
use LogicException;

final readonly class FieldTypeRegistry
{
    /** @var array<string,FieldTypeInterface> */
    private array $types;

    /**
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
     * Field IDs keep file collections stable across renames and layout changes.
     */
    public static function mediaUsage(int $fieldId): string
    {
        if ($fieldId <= 0) {
            throw new InvalidArgumentException('Media field must be persisted.');
        }
        return 'field_' . $fieldId;
    }

    /**
     * @param array<string,mixed> $field
     */
    public function mediaDefinition(string $module, array $field): ?AdminFileUsageDefinition
    {
        if ($this->get((string) $field['kind'])->storage() !== null || $field['archived_at'] !== null) {
            return null;
        }
        $image = in_array($field['kind'], ['image', 'gallery'], true);
        $multiple = in_array($field['kind'], ['gallery', 'files'], true);
        return new AdminFileUsageDefinition(
            moduleCode: $module,
            usage: self::mediaUsage((int) $field['id']),
            kind: $image ? 'image' : 'file',
            profile: $image ? 'admin-image' : 'admin-file',
            multiple: $multiple,
            sortable: $multiple,
            imageProfile: $image ? null : 'admin-image',
        );
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->types);
    }

    public function get(string $code): FieldTypeInterface
    {
        return $this->types[$code] ?? throw new InvalidArgumentException('Unsupported field kind: ' . $code);
    }

    /**
     * @return array<string,null>
     */
    public function emptyValues(): array
    {
        $values = [];
        foreach ($this->types as $type) {
            if ($type->storage() !== null) {
                $values['default_' . $type->storage()] = null;
            }
            foreach ($type->ruleControls() as $name => $control) {
                if ($control !== null) {
                    $values[$name] = null;
                }
            }
        }
        return $values;
    }

    /**
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
     * @return array<string,list<AdminEditorFieldDefinition>>
     */
    public function inspectors(): array
    {
        $widths = range(1, 12);
        $widthOptions = array_combine($widths, array_map(static fn(int $span): string => $span . '/12', $widths));
        $inspectors = [];
        foreach ($this->types as $code => $type) {
            $scalar = $type->storage() !== null;
            $controls = [
                Field::text('name', labelKey: 'content-types.fields.name')->required()->attributes(['maxlength' => '180']),
                Field::text('code', labelKey: 'content-types.fields.code')->help('', 'content-types.editor.code_help')->attributes(['maxlength' => '64']),
                ...($scalar ? [
                    Field::toggle('required', labelKey: 'content-types.fields.required'),
                    Field::toggle('localized', labelKey: 'content-types.fields.localized'),
                ] : []),
                Field::textarea('help', labelKey: 'content-types.fields.help'),
                ...array_values(array_filter($type->ruleControls())),
                ...($scalar ? [$type->control('default_value', 'content-types.fields.default_value')] : []),
                Field::text('css_class', labelKey: 'content-types.fields.css_class')->attributes(['maxlength' => '255']),
                Field::text('html_id', labelKey: 'content-types.fields.html_id')->help('', 'content-types.editor.html_id_help')->attributes(['maxlength' => '128']),
                Field::select('span', labelKey: 'content-types.fields.width')->options($widthOptions),
            ];
            $inspectors[$code] = array_map(
                static fn(AdminEditorFieldDefinition $field): AdminEditorFieldDefinition => $field->helpKey($field->helpTranslationKey() ?? 'content-types.help.' . $field->name()),
                array_values(array_filter($controls)),
            );
        }
        return $inspectors;
    }

    /**
     * @param array<string,mixed> $definition
     */
    public function contentField(array $definition): AdminEditorInputFieldDefinition
    {
        $type = $this->get((string) $definition['kind']);
        $control = $type->control('field_' . $definition['id']);
        if ($control === null) {
            throw new LogicException('Media collections are rendered as custom blocks.');
        }
        $field = $control
            ->label((string) $definition['name'])
            ->help((string) ($definition['help'] ?? ''))
            ->required((int) $definition['required'] === 1);
        if ($field instanceof SelectField) {
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
        $rules = $type->ruleControls();
        $ruleAttributes = [
            'min_length' => 'minlength',
            'max_length' => 'maxlength',
            'min_number' => 'min',
            'max_number' => 'max',
            'min_date' => 'min',
            'max_date' => 'max',
        ];
        foreach ($ruleAttributes as $rule => $attribute) {
            if (isset($rules[$rule]) && ($projection[$rule] ?? '') !== '') {
                $attributes[$attribute] = (string) $projection[$rule];
            }
        }
        if (isset($rules['decimal_places'])) {
            $places = (int) ($definition['decimal_places'] ?? 2);
            $attributes['step'] = $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
        }
        return $field->attributes($attributes);
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public function editorField(array $field): array
    {
        $field = $this->get((string) $field['kind'])->editorValues($field) + $field;
        $field['id'] = (int) $field['id'];
        $field['key'] = 'f' . $field['id'];
        $field['archived'] = $field['archived_at'] !== null;
        $options = [];
        foreach ($field['options'] as $option) {
            if ($option['archived_at'] === null) {
                $options[] = [
                    'id' => (int) $option['id'],
                    'code' => (string) $option['code'],
                    'name' => (string) $option['name'],
                ];
            }
        }
        $field['options'] = $options;
        return $field;
    }
}
