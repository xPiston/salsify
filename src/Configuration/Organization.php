<?php

declare(strict_types=1);

namespace Piston\Salsify\Configuration;

use Piston\Salsify\Exception\InvalidArgument;
use Stringable;

/**
 * The organization id: an `s-` followed by hex groups, taken from the URL of
 * the Salsify app right after `/orgs/`.
 *
 * It is in the path of every request, and it is not optional in practice even
 * though the documentation calls it preferred: a user who belongs to more than
 * one organization gets a 400 without it - a helpful one, which lists the
 * organizations by name and id, and which this SDK surfaces rather than
 * swallows.
 *
 * The shape is validated loosely on purpose. Salsify's own examples show
 * `s-999-999-999-999` and `s-9c9999-9999-9999-9999`, which are not the same
 * number of groups or the same group lengths, and a library that insisted on
 * one of them would reject real organizations to enforce a pattern nobody
 * documented.
 */
final class Organization implements Stringable
{
    private function __construct(private readonly string $id) {}

    public static function of(string $id): self
    {
        $id = trim($id);

        if (preg_match('/^s-[0-9a-f-]{4,}$/i', $id) !== 1) {
            throw InvalidArgument::because(
                'organization id',
                'expected the id from the app URL after /orgs/, such as s-9999-9999-9999-9999',
                $id,
            );
        }

        return new self($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
