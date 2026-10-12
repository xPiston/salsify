<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Integration;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Salsify;
use Piston\Salsify\Tests\Support\Certificates;
use Piston\Salsify\Webhooks\InvalidSignature;
use Piston\Salsify\Webhooks\SignedRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A webhook arriving at an application, verified the way one really would be.
 *
 * The unit tests hand the verifier a certificate directly. This one makes it
 * download the certificate over PSR-18 from the URL in the header - which is
 * the path that has to be safe, because that URL comes from the network - and
 * it does the whole thing through `Salsify::webhooks()`, the method an
 * application actually calls, rather than through a verifier assembled by
 * hand in the test.
 *
 * Salsify's key is not needed for any of it: an RSA-SHA256 signature over a
 * known string, checked against a certificate, behaves identically whoever
 * made the key. So the test plays Salsify, and can then forge what Salsify
 * never would.
 */
final class WebhookDeliveryTest extends TestCase
{
    private const ENDPOINT = 'https://shop.example.com/webhooks/salsify';

    private const ORGANIZATION = 's-1234-5678-9012-3456';

    private const CERTIFICATE_URL = 'https://webhooks-auth.salsify.com/certificates/2026-02.pem';

    #[Test]
    public function test_a_real_delivery_is_accepted_and_the_certificate_is_downloaded_once(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport($authority->pem);

        $salsify = new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport);
        $verifier = $salsify->webhooks(self::ENDPOINT);

        $first = $this->delivery($authority, 'req-1', '{"salsify:id":"ABC-1","salsify:entity_type":"record"}');
        $second = $this->delivery($authority, 'req-2', '{"salsify:id":"ABC-2","salsify:entity_type":"record"}');

        $verifier->verify($first);
        $verifier->verify($second);

        self::assertSame(1, $transport->calls, 'the certificate is fetched once per process, not once per webhook');
        self::assertSame(
            self::CERTIFICATE_URL,
            (string) $transport->lastUri,
            'and it is fetched from the URL in the header, once that URL has been vetted',
        );
    }

    #[Test]
    public function test_the_body_is_verified_as_it_arrived_not_as_php_would_re_encode_it(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport($authority->pem);
        $verifier = (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport))
            ->webhooks(self::ENDPOINT);

        // Key order, spacing and a float that does not survive a round trip.
        $raw = '{"b":1, "a":2, "price": 1.10, "salsify:id":"X"}';

        self::assertNotSame(
            $raw,
            (string) json_encode(json_decode($raw, true)),
            'this body deliberately does not survive decode/encode',
        );

        $verifier->verify($this->delivery($authority, 'req-3', $raw));

        // The same signature against the re-encoded body is not valid, which
        // is why SignedRequest takes raw bytes and the bridges read the raw
        // request body rather than the framework's parsed input.
        $reEncoded = (string) json_encode(json_decode($raw, true));
        $forged = $this->delivery($authority, 'req-3', $raw, bodyDelivered: $reEncoded);

        self::assertFalse($verifier->isValid($forged));
    }

    #[Test]
    public function test_a_delivery_signed_for_another_endpoint_is_refused(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport($authority->pem);

        // Salsify signed the URL it was configured to call. This application
        // is verifying with a different one - a staging host, say - and the
        // signature must not pass just because everything else lines up.
        $verifier = (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport))
            ->webhooks('https://staging.example.com/webhooks/salsify');

        $delivery = $this->delivery($authority, 'req-4', '{"salsify:id":"X"}');

        $this->expectException(InvalidSignature::class);

        $verifier->verify($delivery);
    }

    #[Test]
    public function test_a_certificate_url_pointing_at_the_internal_network_is_never_requested(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport($authority->pem);
        $verifier = (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport))
            ->webhooks(self::ENDPOINT);

        $delivery = $this->delivery(
            $authority,
            'req-5',
            '{"salsify:id":"X"}',
            certificateUrl: 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        );

        self::assertFalse($verifier->isValid($delivery));
        self::assertSame(0, $transport->calls, 'the URL is vetted before anything is fetched');
    }

    #[Test]
    public function test_a_certificate_the_host_serves_as_an_error_page_is_not_a_certificate(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport('<html>503 Service Unavailable</html>', status: 503);
        $verifier = (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport))
            ->webhooks(self::ENDPOINT);

        $this->expectException(InvalidSignature::class);
        $this->expectExceptionMessageMatches('/503/');

        $verifier->verify($this->delivery($authority, 'req-6', '{"salsify:id":"X"}'));
    }

    #[Test]
    public function test_the_headers_are_matched_however_the_server_spells_them(): void
    {
        $authority = Certificates::create();
        $transport = new CountingTransport($authority->pem);
        $verifier = (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $transport))
            ->webhooks(self::ENDPOINT);

        $body = '{"salsify:id":"X"}';
        $timestamp = (string) time();
        $signature = $authority->sign(implode('.', [$timestamp, 'req-7', self::ORGANIZATION, self::ENDPOINT, $body]));

        // PHP's own superglobal spelling, which is what a plain-PHP
        // application has to hand: upper case, underscores, HTTP_ prefix.
        $superglobal = SignedRequest::fromHeaders([
            'HTTP_X_SALSIFY_TIMESTAMP' => $timestamp,
            'HTTP_X_SALSIFY_REQUEST_ID' => 'req-7',
            'HTTP_X_SALSIFY_ORGANIZATION_ID' => self::ORGANIZATION,
            'HTTP_X_SALSIFY_SIGNATURE_V1' => $signature,
            'HTTP_X_SALSIFY_CERT_URL' => self::CERTIFICATE_URL,
            'HTTP_HOST' => 'shop.example.com',
            'REQUEST_METHOD' => 'POST',
        ], $body);

        // PSR-7's spelling, lower case with a list per header.
        $psr7 = SignedRequest::fromHeaders([
            'x-salsify-timestamp' => [$timestamp],
            'x-salsify-request-id' => ['req-7'],
            'x-salsify-organization-id' => [self::ORGANIZATION],
            'x-salsify-signature-v1' => [$signature],
            'x-salsify-cert-url' => [self::CERTIFICATE_URL],
        ], $body);

        self::assertTrue($verifier->isValid($superglobal));
        self::assertTrue($verifier->isValid($psr7));
    }

    private function delivery(
        Certificates $authority,
        string $requestId,
        string $body,
        ?string $bodyDelivered = null,
        string $certificateUrl = self::CERTIFICATE_URL,
        ?string $timestamp = null,
    ): SignedRequest {
        $timestamp ??= (string) time();

        $signature = $authority->sign(implode('.', [
            $timestamp,
            $requestId,
            self::ORGANIZATION,
            self::ENDPOINT,
            $body,
        ]));

        return SignedRequest::fromHeaders([
            SignedRequest::TIMESTAMP => $timestamp,
            SignedRequest::REQUEST_ID => $requestId,
            SignedRequest::ORGANIZATION_ID => self::ORGANIZATION,
            SignedRequest::SIGNATURE => $signature,
            SignedRequest::CERTIFICATE_URL => $certificateUrl,
        ], $bodyDelivered ?? $body);
    }
}

/** A PSR-18 client that serves one document and counts who asked. */
final class CountingTransport implements ClientInterface
{
    public int $calls = 0;

    public ?string $lastUri = null;

    public function __construct(private readonly string $document, private readonly int $status = 200) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->calls++;
        $this->lastUri = (string) $request->getUri();

        return new Response($this->status, ['Content-Type' => 'application/x-pem-file'], $this->document);
    }
}
