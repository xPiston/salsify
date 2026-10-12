<?php

declare(strict_types=1);

namespace Piston\Salsify\Entity;

use DateTimeImmutable;

/**
 * Reads the dates Salsify sends, and only those.
 *
 * `new DateTimeImmutable($value)` would be shorter and is wrong here: it
 * accepts "yesterday", "now", "+1 week" and a good deal else, so a corrupt or
 * truncated field comes back as a plausible date instead of nothing. For a
 * value an application will store and compare, a wrong date is worse than a
 * missing one.
 *
 * So the documented shape is parsed explicitly - `YYYY-MM-DDTHH:MM:SS.MSZ`,
 * which is what the product and record documentation specifies - with the
 * variants their own examples show, and anything else is null.
 */
final class Timestamp
{
    private const FORMATS = [
        'Y-m-d\TH:i:s.vp',   // 2021-06-21T18:28:02.935Z
        'Y-m-d\TH:i:sp',     // 2021-06-21T18:28:02Z
        'Y-m-d\TH:i:s.vP',   // with an offset
        'Y-m-d\TH:i:sP',
        'Y-m-d H:i:s',
    ];

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, trim($value));

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return null;
    }
}
