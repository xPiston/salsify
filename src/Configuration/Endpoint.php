<?php

declare(strict_types=1);

namespace Piston\Salsify\Configuration;

/**
 * Where a call goes - and there are two answers, which is Salsify's doing
 * rather than this library's.
 *
 * Records live under `/api/v1/orgs/{org}/...`. Export runs live under
 * `/api/orgs/{org}/...`, with no version segment at all: it is what the
 * OpenAPI definition on Salsify's own "Start Export Run" page declares as its
 * server. An SDK that assumed one prefix for everything would work perfectly
 * until the first export.
 *
 * So the version is part of the call rather than part of the configuration,
 * and `Endpoint` is the one place that knows which is which.
 */
final class Endpoint
{
    public const DEFAULT_HOST = 'https://app.salsify.com';

    public function __construct(
        private readonly Organization $organization,
        private readonly string $host = self::DEFAULT_HOST,
    ) {}

    /** A versioned path: records, digital assets, everything under /api/v1. */
    public function versioned(string $path): string
    {
        return $this->build('/api/v1/orgs/', $path);
    }

    /** An unversioned path: export runs, imports, channels. */
    public function unversioned(string $path): string
    {
        return $this->build('/api/orgs/', $path);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function organization(): Organization
    {
        return $this->organization;
    }

    private function build(string $prefix, string $path): string
    {
        return rtrim($this->host, '/').$prefix.$this->organization->id().'/'.ltrim($path, '/');
    }
}
