<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Support;

use Piston\Salsify\Webhooks\CertificateFetcher;
use Piston\Salsify\Webhooks\InvalidSignature;

/**
 * A certificate fetcher that answers from memory and counts its calls.
 *
 * The count is the point. The most important thing the verifier does with an
 * untrusted certificate URL is **not fetch it** - a verifier that downloads
 * first and validates afterwards has already made the request an attacker
 * wanted. Asserting that this was never called is how that is tested.
 */
final class RecordingFetcher implements CertificateFetcher
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, string> $certificates keyed by URL */
    public function __construct(private readonly array $certificates = [], private readonly ?string $always = null) {}

    public static function returning(string $pem): self
    {
        return new self([], $pem);
    }

    public function fetch(string $url): string
    {
        $this->calls[] = $url;

        $pem = $this->always ?? ($this->certificates[$url] ?? null);

        if ($pem === null) {
            throw InvalidSignature::unreadableCertificate('nothing is registered at '.$url);
        }

        return $pem;
    }

    public function wasCalled(): bool
    {
        return $this->calls !== [];
    }
}
