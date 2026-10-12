<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

use Piston\Salsify\Entity\Record;

/**
 * One line of a JSON Lines export.
 *
 * Every line carries `salsify:entity_type`, and the file is a data model told
 * in order: a header, then the properties, then their enumerated values, then
 * the digital assets, then the records that refer to all of it. Reading it in
 * order is reading a schema before the rows that use it.
 *
 * Unknown entity types are kept rather than skipped. Salsify adds to this
 * format - and a reader that dropped what it did not recognise would silently
 * lose data from a newer organization than itself, which for a mirror is the
 * worst possible failure.
 */
final class Line
{
    public const HEADER = 'header';

    public const PROPERTY = 'property';

    public const ENUMERATED_VALUE = 'enumerated_value';

    public const DIGITAL_ASSET = 'digital_asset';

    public const RECORD = 'record';

    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data) {}

    public function entityType(): ?string
    {
        $type = $this->data['salsify:entity_type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    public function isRecord(): bool
    {
        return $this->entityType() === self::RECORD;
    }

    public function isProperty(): bool
    {
        return $this->entityType() === self::PROPERTY;
    }

    public function isEnumeratedValue(): bool
    {
        return $this->entityType() === self::ENUMERATED_VALUE;
    }

    public function isDigitalAsset(): bool
    {
        return $this->entityType() === self::DIGITAL_ASSET;
    }

    public function isHeader(): bool
    {
        return $this->entityType() === self::HEADER;
    }

    /**
     * The line as a record.
     *
     * A record line carries its properties directly, without the
     * `salsify:entity_type` marker being one of them - so that is dropped and
     * everything else is kept.
     */
    public function toRecord(): Record
    {
        $properties = $this->data;
        unset($properties['salsify:entity_type']);

        return Record::fromArray($properties);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function id(): ?string
    {
        $id = $this->data['salsify:id'] ?? null;

        return is_string($id) || is_int($id) ? (string) $id : null;
    }
}
