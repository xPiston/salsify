<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

/**
 * Remembers certificates for the life of the process.
 *
 * Salsify signs with a handful of certificates and rotates them rarely, while
 * a webhook endpoint on a busy organization is called constantly. Without
 * this, every single webhook costs an outbound HTTPS round trip to
 * webhooks-auth.salsify.com before the application does anything - which is
 * latency on the critical path and a dependency on somebody else's uptime for
 * every message.
 *
 * In memory only, keyed by URL. A certificate at a given URL does not change -
 * a rotation is a new URL - so there is nothing to invalidate and no clock to
 * get wrong. An application that wants it to survive between requests wraps
 * its own cache around the same interface.
 */
final class CachedCertificates implements CertificateFetcher
{
    /** @var array<string, string> */
    private array $certificates = [];

    public function __construct(private readonly CertificateFetcher $fetcher) {}

    public function fetch(string $url): string
    {
        return $this->certificates[$url] ??= $this->fetcher->fetch($url);
    }

    public function forget(): void
    {
        $this->certificates = [];
    }
}
