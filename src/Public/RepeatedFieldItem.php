<?php

declare(strict_types=1);

namespace Lemonade\Cms\ContentTypes\Public;

use InvalidArgumentException;
use Lemonade\Cms\ContentTypes\Record\RecordFieldValue;

/**
 * Represents one stable item in an ordered repeatable field
 */
final readonly class RepeatedFieldItem
{
    /**
     * Creates a repeatable item projection
     *
     * @param array<string,RecordFieldValue> $fields
     */
    public function __construct(private int $id, private array $fields) {}

    /**
     * Returns the stable item identifier
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns child values by their stable codes
     *
     * @return array<string,RecordFieldValue>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns one child value or rejects an unknown code
     */
    public function getField(string $code): RecordFieldValue
    {
        return $this->fields[$code] ?? throw new InvalidArgumentException('Unknown repeated field: ' . $code);
    }
}
