<?php

declare(strict_types=1);

namespace Piston\Salsify\Http;

/**
 * A successful answer: the decoded body, and what it said about the budget.
 *
 * The rate limit travels with every reply rather than being fetched from the
 * client afterwards, because the two belong to the same moment - and because
 * an application that loops is reading it in the loop.
 */
final class Reply
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly RateLimit $rateLimit,
    ) {}

    public function get(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }

    public function string(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /** @return array<array-key, mixed> */
    public function array(string $key): array
    {
        $value = $this->body[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
