<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Webhooks;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Tests\Support\Certificates;
use Piston\Salsify\Tests\Support\RecordingFetcher;
use Piston\Salsify\Webhooks\InvalidSignature;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Piston\Salsify\Webhooks\SignedRequest;

/**
 * The webhook verifier, against a certificate authority the test owns.
 *
 * Salsify's key is not needed to prove this: the algorithm is RSA-SHA256 over
 * a string built in a documented order, and it behaves the same whoever holds
 * the key. So the suite signs as Salsify signs, then produces every forgery a
 * genuine sender never would.
 *
 * The positive case alone would pass for a verifier that accepts everything;
 * the negatives alone would pass for one that accepts nothing. Both are here.
 */
final class SignatureVerifierTest extends TestCase
{
    private const ENDPOINT = 'https://shop.test/webhooks/salsify';

    private const CERT_URL = 'https://webhooks-auth.salsify.com/certs/abc123.pem';

    private const BODY = '{"salsify:entity_type":"record","salsify:id":"3635065","Display Name":"40 in. TV"}';

    public function test_a_webhook_signed_the_way_salsify_signs_is_accepted(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);
        $fetcher = RecordingFetcher::returning($authority->pem);

        $this->verifier($fetcher)->verify($request, $this->now());

        $this->assertTrue($this->verifier($fetcher)->isValid($request, $this->now()));
        $this->assertSame([self::CERT_URL], array_slice($fetcher->calls, 0, 1));
    }

    /**
     * Each of these changes one byte of what was signed. Without them, the
     * test above would pass for a verifier that returns true.
     *
     * @param  array<string, string>  $changes
     */
    #[DataProvider('tamperings')]
    public function test_a_webhook_whose_signed_parts_changed_is_refused(array $changes): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority, $changes);

        $this->expectException(InvalidSignature::class);
        $this->expectExceptionMessage('The signature does not match this request');

        $this->verifier(RecordingFetcher::returning($authority->pem))->verify($request, $this->now());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function tamperings(): iterable
    {
        yield 'the body' => [['body' => self::BODY.' ']];
        yield 'the body, rewritten identically but reordered' => [['body' => '{"salsify:id":"3635065","salsify:entity_type":"record","Display Name":"40 in. TV"}']];
        yield 'the request id' => [['requestId' => 'another-request']];
        yield 'the organization id' => [['organizationId' => 's-0000-0000-0000-0000']];
        yield 'the timestamp' => [['timestamp' => 'shift']];
    }

    /**
     * The endpoint URL is in the signature but not in the request, so a
     * verifier configured with the wrong one refuses everything - which is
     * the correct and least surprising failure.
     */
    public function test_a_verifier_configured_with_another_url_refuses(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);

        $verifier = new SignatureVerifier(
            'https://shop.test/webhooks/salsify-staging',
            RecordingFetcher::returning($authority->pem),
        );

        $this->expectException(InvalidSignature::class);

        $verifier->verify($request, $this->now());
    }

    public function test_a_webhook_signed_by_another_certificate_is_refused(): void
    {
        $salsify = Certificates::create();
        $impostor = Certificates::create();

        // Signed by the impostor, verified against Salsify's certificate.
        $request = $this->sign($impostor);

        $this->expectException(InvalidSignature::class);

        $this->verifier(RecordingFetcher::returning($salsify->pem))->verify($request, $this->now());
    }

    public function test_a_replayed_webhook_is_refused(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);

        // Six minutes later: Salsify recommends a five-minute tolerance.
        $later = $this->now()->modify('+6 minutes');

        try {
            $this->verifier(RecordingFetcher::returning($authority->pem))->verify($request, $later);
            $this->fail('a six-minute-old webhook should have been refused');
        } catch (InvalidSignature $e) {
            $this->assertSame(InvalidSignature::EXPIRED, $e->reason);
            $this->assertStringContainsString('replay', $e->getMessage());
        }
    }

    public function test_a_webhook_just_inside_the_tolerance_is_accepted(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);

        $this->verifier(RecordingFetcher::returning($authority->pem))
            ->verify($request, $this->now()->modify('+4 minutes 59 seconds'));

        $this->addToAssertionCount(1);
    }

    /**
     * Salsify's own tolerance is one-sided - it only says to ignore old
     * requests - which would leave a signature with a timestamp far ahead
     * valid for as long as that timestamp is in the future.
     */
    public function test_a_webhook_from_the_future_is_refused(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);

        try {
            $this->verifier(RecordingFetcher::returning($authority->pem))
                ->verify($request, $this->now()->modify('-1 hour'));
            $this->fail('a webhook signed an hour ahead should have been refused');
        } catch (InvalidSignature $e) {
            $this->assertSame(InvalidSignature::FROM_THE_FUTURE, $e->reason);
        }
    }

    public function test_a_little_clock_skew_is_tolerated_in_both_directions(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);
        $verifier = $this->verifier(RecordingFetcher::returning($authority->pem));

        $verifier->verify($request, $this->now()->modify('-30 seconds'));
        $verifier->verify($request, $this->now()->modify('+30 seconds'));

        $this->addToAssertionCount(2);
    }

    /**
     * The one that is not about forgery but about what the verifier does to
     * *itself*: fetching whatever URL a header names is a server-side request
     * forgery. The assertion that matters is that nothing was fetched.
     */
    #[DataProvider('untrustedUrls')]
    public function test_an_untrusted_certificate_url_is_never_fetched(string $url): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority, ['certificateUrl' => $url]);
        $fetcher = RecordingFetcher::returning($authority->pem);

        try {
            $this->verifier($fetcher)->verify($request, $this->now());
            $this->fail('the certificate URL '.$url.' should have been refused');
        } catch (InvalidSignature $e) {
            $this->assertSame(InvalidSignature::UNTRUSTED_CERTIFICATE_URL, $e->reason);
        }

        $this->assertFalse($fetcher->wasCalled(), 'an untrusted URL must not be requested at all');
    }

    /** @return iterable<string, array{string}> */
    public static function untrustedUrls(): iterable
    {
        yield 'plain http' => ['http://webhooks-auth.salsify.com/certs/abc.pem'];
        yield 'another host' => ['https://attacker.test/certs/abc.pem'];
        yield 'a subdomain of the attacker' => ['https://webhooks-auth.salsify.com.attacker.test/certs/abc.pem'];
        yield 'a subdomain of Salsify' => ['https://evil.webhooks-auth.salsify.com/certs/abc.pem'];
        yield 'the host as a path' => ['https://attacker.test/webhooks-auth.salsify.com/abc.pem'];
        yield 'credentials in the URL' => ['https://webhooks-auth.salsify.com@attacker.test/abc.pem'];
        yield 'a file path' => ['file:///etc/passwd'];
        yield 'the metadata service' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'nonsense' => ['not a url'];
    }

    public function test_the_trusted_url_check_is_available_on_its_own(): void
    {
        $this->assertTrue(SignatureVerifier::isTrustedCertificateUrl(self::CERT_URL));
        $this->assertTrue(SignatureVerifier::isTrustedCertificateUrl('https://WEBHOOKS-AUTH.SALSIFY.COM/x.pem'));
        $this->assertFalse(SignatureVerifier::isTrustedCertificateUrl('https://attacker.test/x.pem'));
    }

    public function test_a_signature_that_is_not_base64_is_refused(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority, ['signature' => '!!! not base64 !!!']);

        $this->expectException(InvalidSignature::class);

        $this->verifier(RecordingFetcher::returning($authority->pem))->verify($request, $this->now());
    }

    public function test_a_certificate_that_is_not_a_certificate_is_refused(): void
    {
        $authority = Certificates::create();
        $request = $this->sign($authority);

        try {
            $this->verifier(RecordingFetcher::returning('-----BEGIN CERTIFICATE-----\nrubbish\n'))
                ->verify($request, $this->now());
            $this->fail('a broken certificate should have been refused');
        } catch (InvalidSignature $e) {
            $this->assertSame(InvalidSignature::UNREADABLE_CERTIFICATE, $e->reason);
        }
    }

    private function verifier(RecordingFetcher $fetcher): SignatureVerifier
    {
        return new SignatureVerifier(self::ENDPOINT, $fetcher);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-12 14:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Salsify's side: build the five-part string, sign it, and hand back the
     * request as it would arrive - with any one part changed *after* signing.
     *
     * @param  array<string, string>  $changes
     */
    private function sign(Certificates $authority, array $changes = []): SignedRequest
    {
        $timestamp = (string) $this->now()->getTimestamp();
        $requestId = 'f1d2d2f9-24e1-4d7e-9a1b-2c3d4e5f6a7b';
        $organizationId = 's-1234-5678-9012-3456';
        $body = self::BODY;

        $signature = $authority->sign(implode('.', [$timestamp, $requestId, $organizationId, self::ENDPOINT, $body]));

        // Then the tampering, which the signature cannot cover.
        $timestamp = isset($changes['timestamp'])
            ? (string) ($this->now()->getTimestamp() + 1)
            : $timestamp;

        return SignedRequest::fromHeaders([
            'X-Salsify-Timestamp' => $timestamp,
            'X-Salsify-Request-ID' => $changes['requestId'] ?? $requestId,
            'X-Salsify-Organization-ID' => $changes['organizationId'] ?? $organizationId,
            'X-Salsify-Signature-V1' => $changes['signature'] ?? $signature,
            'X-Salsify-Cert-Url' => $changes['certificateUrl'] ?? self::CERT_URL,
        ], $changes['body'] ?? $body);
    }
}
