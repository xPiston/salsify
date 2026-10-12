<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Downloads the certificate over PSR-18.
 *
 * It re-checks the URL before fetching, even though the verifier has already
 * done it. That is not redundancy for its own sake: this class is public, and
 * the day somebody calls it directly with a URL out of a request, the check
 * that stops a server-side request forgery has to be the one next to the
 * network call - not the one in the caller that was skipped.
 */
final class HttpCertificateFetcher implements CertificateFetcher
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly string $expectedHost = SignatureVerifier::CERTIFICATE_HOST,
    ) {}

    public function fetch(string $url): string
    {
        if (! SignatureVerifier::isTrustedCertificateUrl($url, $this->expectedHost)) {
            throw InvalidSignature::untrustedCertificateUrl($url, $this->expectedHost);
        }

        try {
            $response = $this->http->sendRequest($this->requests->createRequest('GET', $url));
        } catch (ClientExceptionInterface $e) {
            throw InvalidSignature::unreadableCertificate('it could not be downloaded: '.$e->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            throw InvalidSignature::unreadableCertificate(sprintf('the certificate URL answered %d', $response->getStatusCode()));
        }

        $pem = (string) $response->getBody();

        if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
            throw InvalidSignature::unreadableCertificate('what came back is not a PEM certificate');
        }

        return $pem;
    }
}
