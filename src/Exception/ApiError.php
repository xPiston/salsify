<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use RuntimeException;

/**
 * Salsify answered, and the answer was a refusal.
 *
 * It keeps the status, the decoded body and the request that caused it,
 * because Salsify's errors are usually specific and usually readable - a 400
 * for an ambiguous organization even lists the organizations you belong to -
 * and an SDK that reduced that to "request failed" would be throwing away the
 * most useful thing in the response.
 */
class ApiError extends RuntimeException implements SalsifyException
{
    /** @param array<string, mixed> $body */
    final public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $method,
        public readonly string $path,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $body */
    public static function from(int $status, array $body, string $method, string $path): self
    {
        $message = sprintf('%s %s answered %d', $method, $path, $status);
        $detail = self::detail($body);

        if ($detail !== null) {
            $message .= ': '.$detail;
        }

        return new self($status, $body, $method, $path, $message);
    }

    /**
     * The messages Salsify puts in an error body.
     *
     * It uses `error` for one or several strings and `errors` elsewhere, so
     * both are read rather than one guessed at.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach (['error', 'errors', 'message'] as $key) {
            $value = $this->body[$key] ?? null;

            if (is_string($value)) {
                $messages[] = $value;
            }

            if (is_array($value)) {
                foreach ($value as $entry) {
                    if (is_string($entry)) {
                        $messages[] = $entry;
                    }
                }
            }
        }

        return $messages;
    }

    /** @param array<string, mixed> $body */
    private static function detail(array $body): ?string
    {
        foreach (['error', 'errors', 'message'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return $value[0];
            }
        }

        return null;
    }
}
