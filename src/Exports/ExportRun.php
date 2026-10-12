<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

use DateTimeImmutable;
use Piston\Salsify\Entity\Timestamp;

/**
 * An export in progress, or finished.
 *
 * The shape is the one Salsify documents for a start request: an id, a status,
 * a progress percentage, and a `url` that is an **empty string** until the
 * file exists. That empty string is the detail worth encoding - code that
 * checks `isset($run['url'])` finds it set, and downloads nothing.
 */
final class ExportRun
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

    /** Where the file is, once there is one. */
    public function url(): ?string
    {
        $url = $this->body['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Finished, with a file to download. */
    public function isReady(): bool
    {
        return $this->url() !== null;
    }

    public function hasFailed(): bool
    {
        return $this->failureReason() !== null || $this->status() === 'failed';
    }

    public function failureReason(): ?string
    {
        $reason = $this->body['failure_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** 0 to 100, as far as Salsify knows. */
    public function progress(): ?int
    {
        $progress = $this->body['progress'] ?? null;

        return is_int($progress) ? $progress : (is_float($progress) ? (int) $progress : null);
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->time('start_time');
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->time('end_time');
    }

    private function time(string $key): ?DateTimeImmutable
    {
        $value = $this->body[$key] ?? null;

        return Timestamp::parse(is_string($value) ? $value : null);
    }
}
