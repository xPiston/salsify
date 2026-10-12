<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

use DateTimeImmutable;
use OpenSSLAsymmetricKey;

/**
 * Decides whether a webhook really came from Salsify.
 *
 * The algorithm is Salsify's: take five values in a fixed order, join them
 * with periods, and check an RSA-SHA256 signature over the result using the
 * public key of a certificate the request names.
 *
 *     timestamp . request_id . organization_id . endpoint_url . body
 *
 * Four of the five are in the request. The fifth, the endpoint URL, is **not**
 * - and that asymmetry is the heart of it. Salsify signs the URL it was told
 * to call; a verifier must supply the same one from its own configuration. A
 * verifier that reconstructed it from the request - from `Host`, from
 * `REQUEST_URI`, from a proxy header - would be checking a signature against a
 * value the sender controls, which is not a check.
 *
 * Two more things this does that the published example does not spell out:
 *
 * **It validates the certificate URL before fetching it.** The URL arrives in
 * a header, and fetching whatever a header says is a server-side request
 * forgery with extra steps: an attacker points it at an internal address and
 * the application obligingly makes the request. So the scheme must be https
 * and the host must be exactly `webhooks-auth.salsify.com`, checked before
 * anything is downloaded, and checked again inside the fetcher.
 *
 * **It rejects timestamps from the future.** Salsify's own tolerance is only
 * one-sided - "ignore requests that are too old" - which leaves a signature
 * valid forever if its timestamp is set far enough ahead. A few seconds of
 * clock skew is normal; an hour is not.
 */
final class SignatureVerifier
{
    public const CERTIFICATE_HOST = 'webhooks-auth.salsify.com';

    /** Salsify's own recommendation. */
    public const DEFAULT_TOLERANCE = 300;

    public function __construct(
        private readonly string $endpointUrl,
        private readonly CertificateFetcher $certificates,
        private readonly int $toleranceInSeconds = self::DEFAULT_TOLERANCE,
        private readonly string $certificateHost = self::CERTIFICATE_HOST,
    ) {}

    /**
     * Verify, or throw with the reason.
     *
     * @throws InvalidSignature
     */
    public function verify(SignedRequest $request, ?DateTimeImmutable $now = null): void
    {
        $this->assertFresh($request, $now ?? new DateTimeImmutable);

        if (! self::isTrustedCertificateUrl($request->certificateUrl, $this->certificateHost)) {
            throw InvalidSignature::untrustedCertificateUrl($request->certificateUrl, $this->certificateHost);
        }

        $signature = $request->signatureBytes();

        if ($signature === null) {
            throw InvalidSignature::doesNotMatch();
        }

        $publicKey = $this->publicKey($this->certificates->fetch($request->certificateUrl));

        $verified = openssl_verify(
            $request->signedString($this->endpointUrl),
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw InvalidSignature::doesNotMatch();
        }
    }

    /** The same question without the exception. */
    public function isValid(SignedRequest $request, ?DateTimeImmutable $now = null): bool
    {
        try {
            $this->verify($request, $now);

            return true;
        } catch (InvalidSignature) {
            return false;
        }
    }

    /**
     * Is this a URL we are willing to fetch a certificate from?
     *
     * Static and public because the fetcher asks it again before the network
     * call. Host comparison is exact and case-insensitive: a subdomain is not
     * the host, and `webhooks-auth.salsify.com.attacker.test` is not either.
     */
    public static function isTrustedCertificateUrl(string $url, string $expectedHost = self::CERTIFICATE_HOST): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        return strtolower($parts['host']) === strtolower($expectedHost);
    }

    private function assertFresh(SignedRequest $request, DateTimeImmutable $now): void
    {
        $signedAt = $request->signedAt();

        if ($signedAt === null) {
            throw InvalidSignature::missingHeader(SignedRequest::TIMESTAMP);
        }

        $age = $now->getTimestamp() - $signedAt;

        if ($age > $this->toleranceInSeconds) {
            throw InvalidSignature::expired($age, $this->toleranceInSeconds);
        }

        // A little skew is a clock; a lot is a forgery with a long life.
        if (-$age > $this->toleranceInSeconds) {
            throw InvalidSignature::fromTheFuture(-$age);
        }
    }

    private function publicKey(string $pem): OpenSSLAsymmetricKey
    {
        $certificate = @openssl_x509_read($pem);

        if ($certificate === false) {
            throw InvalidSignature::unreadableCertificate('it is not a readable X.509 certificate');
        }

        $key = @openssl_pkey_get_public($certificate);

        if ($key === false) {
            throw InvalidSignature::unreadableCertificate('it carries no usable public key');
        }

        return $key;
    }
}
