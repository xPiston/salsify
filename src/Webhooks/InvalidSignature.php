<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

use Piston\Salsify\Exception\SalsifyException;
use RuntimeException;

/**
 * A webhook that cannot be trusted, and why.
 *
 * The reason is kept because the five causes are five different incidents: a
 * forged payload, a replayed one, a certificate from somewhere that is not
 * Salsify, a misconfigured endpoint URL, and a clock that has drifted. Only
 * the last is innocent, and the only way to tell is to say which happened.
 */
final class InvalidSignature extends RuntimeException implements SalsifyException
{
    public const MISSING_HEADER = 'missing_header';

    public const EXPIRED = 'expired';

    public const FROM_THE_FUTURE = 'from_the_future';

    public const UNTRUSTED_CERTIFICATE_URL = 'untrusted_certificate_url';

    public const UNREADABLE_CERTIFICATE = 'unreadable_certificate';

    public const DOES_NOT_MATCH = 'does_not_match';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function missingHeader(string $header): self
    {
        return new self(self::MISSING_HEADER, sprintf('This request carries no %s header, so there is nothing to verify.', $header));
    }

    public static function expired(int $ageInSeconds, int $toleranceInSeconds): self
    {
        return new self(self::EXPIRED, sprintf(
            'This webhook was signed %d seconds ago and the tolerance is %d: it is a replay, or a clock has drifted.',
            $ageInSeconds,
            $toleranceInSeconds,
        ));
    }

    public static function fromTheFuture(int $secondsAhead): self
    {
        return new self(self::FROM_THE_FUTURE, sprintf(
            'This webhook is signed %d seconds in the future, which no legitimate sender does.',
            $secondsAhead,
        ));
    }

    public static function untrustedCertificateUrl(string $url, string $expectedHost): self
    {
        return new self(self::UNTRUSTED_CERTIFICATE_URL, sprintf(
            'The certificate URL must be https on %s, and this one is "%s". It has not been fetched.',
            $expectedHost,
            $url,
        ));
    }

    public static function unreadableCertificate(string $detail): self
    {
        return new self(self::UNREADABLE_CERTIFICATE, 'The certificate could not be read: '.$detail);
    }

    public static function doesNotMatch(): self
    {
        return new self(self::DOES_NOT_MATCH, 'The signature does not match this request: the body, the URL or the identifiers are not the ones that were signed.');
    }
}
