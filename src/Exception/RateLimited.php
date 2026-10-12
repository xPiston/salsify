<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use DateTimeImmutable;
use Piston\Salsify\Http\RateLimit;

/**
 * 429: the organization's hour is spent.
 *
 * The thing to understand about this one is whose budget it is. Salsify counts
 * ten thousand requests per hour **per organization**, not per user and not per
 * token - so this exception is as likely to mean "another integration used the
 * budget" as "you did", and the window resets at the top of the hour rather
 * than rolling.
 *
 * Which is why this library does not retry it for you. A retry loop inside an
 * SDK, multiplied by however many jobs an application runs, is how an
 * organization spends the next hour at zero as well.
 */
final class RateLimited extends ApiError
{
    public ?RateLimit $rateLimit = null;

    /** @param array<string, mixed> $body */
    public static function with(
        RateLimit $rateLimit,
        array $body,
        string $method,
        string $path,
    ): self {
        $error = new self(
            429,
            $body,
            $method,
            $path,
            sprintf(
                '%s %s answered 429: the organization has used its %d requests for this hour; the limit resets in %d seconds.',
                $method,
                $path,
                $rateLimit->limit ?? 10000,
                $rateLimit->resetsIn ?? 0,
            ),
        );

        $error->rateLimit = $rateLimit;

        return $error;
    }

    /** When it is worth trying again, if the response said. */
    public function resetsAt(?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $seconds = $this->rateLimit?->resetsIn;

        if ($seconds === null) {
            return null;
        }

        return ($now ?? new DateTimeImmutable)->modify(sprintf('+%d seconds', $seconds));
    }
}
