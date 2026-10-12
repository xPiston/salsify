<?php

declare(strict_types=1);

namespace Piston\Salsify\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * What a response says about the organization's remaining budget.
 *
 * Salsify allows ten thousand requests an hour **per organization** - not per
 * user, not per token - and every response carries three headers saying where
 * that stands. This reads them, and the SDK hands the latest one to the
 * application after every call, because the useful moment to slow an import
 * down is before the 429 rather than after it.
 *
 * `resetsIn` is seconds until the top of the hour, not a rolling window: the
 * counter clears at the hour and not sixty minutes after the first request.
 * A job that exhausts the budget at 16:45 resumes at 17:00 with ten thousand.
 */
final class RateLimit
{
    private function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $resetsIn,
    ) {}

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $read = static function (string $header) use ($response): ?int {
            $value = $response->getHeaderLine($header);

            return preg_match('/^-?\d+$/', trim($value)) === 1 ? (int) trim($value) : null;
        };

        return new self(
            $read('x-ratelimit-limit'),
            $read('x-ratelimit-remaining'),
            $read('x-ratelimit-reset'),
        );
    }

    public function isKnown(): bool
    {
        return $this->remaining !== null;
    }

    /**
     * Is the budget nearly gone?
     *
     * The default is a twentieth - five hundred requests of ten thousand -
     * which is about a minute of a busy import and enough warning to stop
     * before the wall rather than after it.
     */
    public function isRunningOut(float $fraction = 0.05): bool
    {
        if ($this->remaining === null || $this->limit === null || $this->limit <= 0) {
            return false;
        }

        return $this->remaining <= $this->limit * $fraction;
    }

    public function isExhausted(): bool
    {
        return $this->remaining !== null && $this->remaining <= 0;
    }

    public function __toString(): string
    {
        if (! $this->isKnown()) {
            return 'rate limit unknown';
        }

        return sprintf(
            '%d of %s requests left, resetting in %s',
            (int) $this->remaining,
            $this->limit === null ? '?' : (string) $this->limit,
            $this->resetsIn === null ? '?' : $this->resetsIn.'s',
        );
    }
}
