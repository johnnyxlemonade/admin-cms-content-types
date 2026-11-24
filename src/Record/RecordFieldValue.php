<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Record;

use DateTimeImmutable;
use Lemonade\Cms\ContentTypes\Field\FieldTypeRegistry;
use UnexpectedValueException;

/**
 * Interprets a stored field value without editor defaults
 */
final readonly class RecordFieldValue
{
    /**
     * Creates a normalized value with its field identity
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
     * Returns the stable field identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the field code
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Returns the field label
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Returns the field kind
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Returns the typed stored value
     */
    public function getValue(): int|string|bool|DateTimeImmutable|null
    {
        return $this->value;
    }

    /**
     * Returns the label of the stored select option
     */
    public function getOptionLabel(): ?string
    {
        return $this->optionLabel;
    }

    /**
     * Interprets a stored value using its field definition and historical options
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
     * Returns the stable file usage for a media field
     */
    public function getMediaUsage(): ?string
    {
        return FieldTypeRegistry::isMedia($this->kind)
            ? FieldTypeRegistry::mediaUsage($this->id) : null;
    }

    /**
     * Rejects an invalid stored date without normalization
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
