<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Field;

use DateTimeImmutable;
use Lemonade\Cms\ContentTypes\Exception\DefinitionValidationException;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\ValidationSchema;

/**
 * Sdili presne validacni operace; typy je skladaji bez vlastnich kopii pravidel
 */
final readonly class FieldValidation
{
    /**
     * Pripojuje canonical frameworkovy validator, slugger a lokalizaci
     */
    public function __construct(private FormValidation $validation, private Slugger $slugger, private TranslatorInterface $translator) {}

    /**
     * Normalizuje nazev a stabilni kod typu nebo pole
     *
     * @param array<string,mixed> $input
     * @return array{name:string,code:string}
     */
    public function identity(array $input): array
    {
        $name = $this->text($input, 'name');
        $code = $this->text($input, 'code');
        if ($code === '') {
            $code = str_replace('-', '_', $this->slugger->slug($name, 64));
        }
        $schema = ValidationSchema::create()
            ->field('name', $this->translator->get('content-types.fields.name'))->required()->maxLength(180)
            ->field('code', $this->translator->get('content-types.fields.code'))->required()->maxLength(64)->regex('/^[a-z][a-z0-9_]*$/D')
            ->end();
        $this->check(['name' => $name, 'code' => $code], $schema);

        return ['name' => $name, 'code' => $code];
    }

    /**
     * Overi textove rozsahy a default; HTML delku pocita bez znacek
     *
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function textDefinition(array $input, bool $formatted = false): array
    {
        $min = $this->naturalRule($input, 'min_length', 1000000);
        $max = $this->naturalRule($input, 'max_length', 1000000);
        if ($min !== null && $max !== null && $min > $max) {
            $this->fail('max_length', 'range');
        }
        $default = $this->text($input, 'default_value');
        if ($default !== '') {
            $schema = ValidationSchema::create();
            $rule = $schema->field('default_value', $this->translator->get('content-types.fields.default_value'));
            if ($min !== null) {
                $rule->minLength($min);
            }
            if ($max !== null) {
                $rule->maxLength($max);
            }
            $this->check(['default_value' => $formatted ? strip_tags($default) : $default], $schema);
        }

        return ['values' => ['min_length' => $min, 'max_length' => $max, 'default_text' => $default === '' ? null : $default], 'options' => []];
    }

    /**
     * Overi numeric pravidla i default jedinou presnou decimal cestou bez floatu
     *
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function numberDefinition(array $input, bool $decimal = false): array
    {
        $scale = $decimal ? ($this->naturalRule($input, 'decimal_places', 8) ?? 2) : 0;
        $numbers = [];
        foreach (['min_number', 'max_number', 'default_value'] as $key) {
            $value = $this->text($input, $key);
            $numbers[$key] = $value === '' ? null : $this->number($value, $scale, $key);
        }
        $min = $numbers['min_number'];
        $max = $numbers['max_number'];
        $default = $numbers['default_value'];
        if ($min !== null && $max !== null && self::compareNumbers($min, $max) > 0) {
            $this->fail('max_number', 'range');
        }
        if ($default !== null && (($min !== null && self::compareNumbers($default, $min) < 0) || ($max !== null && self::compareNumbers($default, $max) > 0))) {
            $this->fail('default_value', 'range');
        }

        return ['values' => ['min_number' => $min, 'max_number' => $max, 'decimal_places' => $decimal ? $scale : null, 'default_' . ($decimal ? 'decimal' : 'integer') => $default === null ? null : ($decimal ? $default : (int) $default)], 'options' => []];
    }

    /**
     * Overi skutecna data, jejich rozsah a default v jednom formatu
     *
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function dateDefinition(array $input, bool $withTime = false): array
    {
        $dates = [];
        foreach (['min_date', 'max_date', 'default_value'] as $key) {
            $value = $this->text($input, $key);
            $dates[$key] = $value === '' ? null : $this->date($value, $withTime, $key);
        }
        $min = $dates['min_date'];
        $max = $dates['max_date'];
        $default = $dates['default_value'];
        if ($min !== null && $max !== null && $min > $max) {
            $this->fail('max_date', 'range');
        }
        if ($default !== null && (($min !== null && $default < $min) || ($max !== null && $default > $max))) {
            $this->fail('default_value', 'range');
        }

        return ['values' => ['min_date' => $min?->format('Y-m-d H:i:s'), 'max_date' => $max?->format('Y-m-d H:i:s'), 'default_' . ($withTime ? 'datetime' : 'date') => $default?->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d')], 'options' => []];
    }

    /**
     * Overi Select moznosti a clenstvi defaultu ve stejnem seznamu
     *
     * @param array<string,mixed> $input
     * @return array{values:array<string,int|string|null>,options:list<array{code:string,name:string}>}
     */
    public function selectDefinition(array $input): array
    {
        $options = $this->options($input['options'] ?? null);
        $default = $this->text($input, 'default_value');
        if ($default !== '' && !in_array($default, array_column($options, 'code'), true)) {
            $this->fail('default_value', 'option');
        }

        return ['values' => ['default_option' => $default === '' ? null : $default], 'options' => $options];
    }

    /**
     * Overi nepovinny nezaporny integer parametr pres framework
     *
     * @param array<string,mixed> $input
     */
    private function naturalRule(array $input, string $key, int $maximum): ?int
    {
        $value = $this->text($input, $key);
        if ($value === '') {
            return null;
        }
        $this->check([$key => $value], ValidationSchema::create()->field($key, $this->translator->get('content-types.fields.' . $key))->integer()->greaterThanOrEqualTo(0)->lessThanOrEqualTo($maximum)->end());

        return (int) $value;
    }

    /**
     * Overuje moznosti Selectu s oddelenym kodem a zobrazovanym nazvem
     *
     * @return list<array{code:string,name:string}>
     */
    public function options(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 200 || $input === []) {
            $this->fail('options', 'option');
        }
        $options = [];
        foreach ($input as $item) {
            if (!is_array($item)) {
                $this->fail('options', 'option');
            }
            try {
                $option = $this->identity($item);
            } catch (DefinitionValidationException) {
                $this->fail('options', 'option');
            }
            if (in_array($option['code'], array_column($options, 'code'), true)) {
                $this->fail('options', 'duplicate');
            }
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Odmitne strukturovany vstup misto textove hodnoty
     *
     * @param array<string,mixed> $input
     */
    public function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) && !is_int($value)) {
            $this->fail($key, 'invalid');
        }

        return trim((string) $value);
    }

    /**
     * Normalizuje standardni boolean transport bez implicitnich truthy hodnot
     *
     * @param array<string,mixed> $input
     */
    public function boolean(array $input, string $key): int
    {
        $value = $this->text($input, $key);
        if (!in_array($value, ['', '0', '1'], true)) {
            $this->fail($key, 'invalid');
        }

        return $value === '1' ? 1 : 0;
    }

    /**
     * Overi cislo bez ztraty presnosti a omezi je na podporovanou SQL presnost
     */
    private function number(string $value, int $scale, string $field): string
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,15})(?:\.([0-9]+))?$/D', $value, $matches) !== 1 || strlen($matches[1] ?? '') > $scale) {
            $this->fail($field, 'number');
        }

        return $value;
    }

    /**
     * Porovna presna desetinna cisla bez float konverze
     */
    public static function compareNumbers(string $left, string $right): int
    {
        $normalize = static function (string $value): string {
            $parts = explode('.', ltrim($value, '-'));

            return str_pad($parts[0], 16, '0', STR_PAD_LEFT) . str_pad($parts[1] ?? '', 8, '0');
        };
        $leftDigits = $normalize($left);
        $rightDigits = $normalize($right);
        $leftNegative = str_starts_with($left, '-') && trim($leftDigits, '0') !== '';
        $rightNegative = str_starts_with($right, '-') && trim($rightDigits, '0') !== '';
        if ($leftNegative !== $rightNegative) {
            return $leftNegative ? -1 : 1;
        }

        return strcmp($leftDigits, $rightDigits) * ($leftNegative ? -1 : 1);
    }

    /**
     * Overi skutecne datum a presny browser format
     */
    private function date(string $value, bool $withTime, string $field): DateTimeImmutable
    {
        $format = $withTime ? 'Y-m-d\TH:i' : 'Y-m-d';
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($parsed === false || $parsed->format($format) !== $value || $parsed->format('Y') < '1000') {
            $this->fail($field, 'date');
        }

        return $parsed;
    }

    /**
     * Pouzije frameworkovy validator a zachova chyby podle poli
     *
     * @param array<string,mixed> $input
     */
    public function check(array $input, ValidationSchema $schema): void
    {
        $result = $this->validation->validate($input, $schema);
        if (!$result->isValid()) {
            throw new DefinitionValidationException($result->errors());
        }
    }

    /**
     * Vrati lokalizovanou chybu vztahu mezi hodnotami definice
     */
    public function fail(string $field, string $reason): never
    {
        throw new DefinitionValidationException([$field => $this->translator->get('content-types.validation.' . $reason)]);
    }
}
