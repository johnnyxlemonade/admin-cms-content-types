<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DateTimeImmutable;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use UnexpectedValueException;

/**
 * Preklada ulozenou hodnotu pole bez aplikace editorovych defaults
 */
final readonly class RecordFieldValue
{
    /**
     * Nastavuje identitu definice a normalizovanou hodnotu
     */
    private function __construct(
        private int $id,
        private string $code,
        private string $label,
        private string $kind,
        private int|string|bool|DateTimeImmutable|null $value,
        private ?string $optionLabel,
    ) {}

    /**
     * Vrati stabilni ID pole
     */
    public function id(): int
    {
        return $this->id;
    }

    /**
     * Vrati kod pole
     */
    public function code(): string
    {
        return $this->code;
    }

    /**
     * Vrati popisek pole
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Vrati druh pole
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * Vrati typovanou ulozenou hodnotu
     */
    public function value(): int|string|bool|DateTimeImmutable|null
    {
        return $this->value;
    }

    /**
     * Vrati popisek ulozene select volby
     */
    public function optionLabel(): ?string
    {
        return $this->optionLabel;
    }

    /**
     * Interpretuje ulozenou hodnotu podle definice vcetne historicke select volby
     *
     * @param array<string,mixed> $field
     */
    public static function fromStored(array $field, int|string|null $stored): self
    {
        $kind = (string) $field['kind'];
        $value = $stored;
        $label = null;
        if ($stored !== null) {
            $value = match ($kind) {
                'integer' => filter_var($stored, FILTER_VALIDATE_INT),
                'boolean' => match ((string) $stored) {
                    '0' => false,
                    '1' => true,
                    default => throw new UnexpectedValueException('Invalid stored boolean.'),
                },
                'date', 'datetime' => self::date((string) $stored, $kind),
                default => (string) $stored,
            };
            if ($kind === 'integer' && $value === false) {
                throw new UnexpectedValueException('Invalid stored integer.');
            }
            if ($kind === 'select') {
                $value = null;
                foreach ($field['options'] as $option) {
                    if ((int) $option['id'] === (int) $stored) {
                        $value = (string) $option['code'];
                        $label = (string) $option['name'];
                        break;
                    }
                }
            }
        }
        return new self((int) $field['id'], (string) $field['code'], (string) $field['name'], $kind, $value, $label);
    }

    /**
     * Vrati stabilni usage pouze pro medialni pole
     */
    public function mediaUsage(): ?string
    {
        return in_array($this->kind, ['image', 'gallery', 'file', 'files'], true)
            ? FieldTypeRegistry::mediaUsage($this->id) : null;
    }

    /**
     * Odmitne neplatne datum bez tiche normalizace
     */
    private static function date(string $value, string $kind): DateTimeImmutable
    {
        $format = $kind === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            throw new UnexpectedValueException('Invalid stored date.');
        }
        return $date;
    }
}
