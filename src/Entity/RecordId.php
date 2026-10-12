<?php

declare(strict_types=1);

namespace Piston\Salsify\Entity;

use Piston\Salsify\Exception\InvalidArgument;
use Stringable;

/**
 * The id of a record or product, as the organization defines it.
 *
 * It is not a Salsify-generated key: it is the value of whichever property
 * holds the ID role - a SKU, a GTIN, an internal reference. Which has a
 * consequence worth knowing before relying on it: **changing that property
 * changes the id**, and the old one stops resolving. Salsify's permanent
 * handle is `salsify:system_id`, and an integration that stores ids rather
 * than system ids will break the day somebody corrects a SKU.
 *
 * Three prefixes are refused because Salsify refuses them: an id cannot start
 * with `=`, `_` or `salsify`. The first because a leading `=` is a formula in
 * the spreadsheet exports, the others because they are reserved.
 */
final class RecordId implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function of(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidArgument::because('record id', 'must not be empty', $value);
        }

        foreach (['=', '_'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                throw InvalidArgument::because('record id', sprintf('must not start with "%s"', $prefix), $value);
            }
        }

        if (str_starts_with(strtolower($value), 'salsify')) {
            throw InvalidArgument::because('record id', 'must not start with "salsify", which is reserved', $value);
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
