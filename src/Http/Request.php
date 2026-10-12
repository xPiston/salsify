<?php

declare(strict_types=1);

namespace Piston\Salsify\Http;

/**
 * One call, before it becomes a PSR-7 request.
 *
 * It exists so that the client has one shape to send and the tests have one
 * shape to assert on - and so that the choice between Salsify's two base
 * paths is made by the caller that knows, rather than guessed at by the
 * transport.
 */
final class Request
{
    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly bool $versioned,
        public readonly array $query = [],
        public readonly ?array $body = null,
    ) {}

    /** @param array<string, scalar|null> $query */
    public static function get(string $path, array $query = [], bool $versioned = true): self
    {
        return new self('GET', $path, $versioned, $query);
    }

    /** @param array<string, mixed> $body */
    public static function post(string $path, array $body = [], bool $versioned = true): self
    {
        return new self('POST', $path, $versioned, [], $body);
    }

    /** @param array<string, mixed> $body */
    public static function put(string $path, array $body = [], bool $versioned = true): self
    {
        return new self('PUT', $path, $versioned, [], $body);
    }

    public static function delete(string $path, bool $versioned = true): self
    {
        return new self('DELETE', $path, $versioned);
    }

    /** The same call against Salsify's unversioned prefix. */
    public function unversioned(): self
    {
        return new self($this->method, $this->path, false, $this->query, $this->body);
    }
}
