<?php

declare(strict_types=1);

namespace Piston\Salsify\Imports;

/**
 * An import in progress, or finished.
 *
 * Kept deliberately thin: the response shape is not published in the detail
 * the export one is, so this keeps the whole body and offers the few fields
 * that are consistent across Salsify's job responses rather than inventing
 * accessors for fields nobody has seen.
 */
final class ImportRun
{
    /** @param array<string, mixed> $body */
    private function __construct(public readonly array $body) {}

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self($body);
    }

    public function id(): ?string
    {
        $id = $this->body['id'] ?? null;

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
    }

    public function status(): ?string
    {
        $status = $this->body['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    public function isRunning(): bool
    {
        return in_array($this->status(), ['running', 'queued', 'pending'], true);
    }

    public function get(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }
}
