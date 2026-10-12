<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * A certificate authority for the tests: generates a key pair and a
 * self-signed certificate, and signs strings with it the way Salsify does.
 *
 * This is what makes the webhook verifier provable without a Salsify account.
 * The algorithm is not a secret and does not need their key: an RSA-SHA256
 * signature over a known string, verified against a certificate, behaves the
 * same whoever generated it. So the suite plays Salsify's side in full and
 * can then produce the forgeries a real sender never would.
 */
final class Certificates
{
    private function __construct(
        private readonly OpenSSLAsymmetricKey $privateKey,
        public readonly string $pem,
    ) {}

    public static function create(string $commonName = 'webhooks-auth.salsify.com'): self
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('could not generate a key pair: '.openssl_error_string());
        }

        // openssl_csr_new takes the key *by reference* - it is allowed to
        // generate one - so it is handed a second variable, and the typed one
        // survives for the calls below.
        $keyForRequest = $privateKey;
        $csr = openssl_csr_new(['commonName' => $commonName], $keyForRequest, ['digest_alg' => 'sha256']);

        if (! $csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('could not generate a certificate request');
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);

        if (! $certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('could not sign the certificate');
        }

        $pem = '';

        if (! openssl_x509_export($certificate, $pem) || ! is_string($pem) || $pem === '') {
            throw new RuntimeException('could not export the certificate');
        }

        return new self($privateKey, $pem);
    }

    /** Salsify's signature: RSA-SHA256 over the string, base64 encoded. */
    public function sign(string $data): string
    {
        $signature = '';

        if (openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256) === false) {
            throw new RuntimeException('could not sign: '.openssl_error_string());
        }

        if (! is_string($signature)) {
            throw new RuntimeException('openssl produced no signature');
        }

        return base64_encode($signature);
    }
}
