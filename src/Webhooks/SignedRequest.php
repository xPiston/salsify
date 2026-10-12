<?php

declare(strict_types=1);

namespace Piston\Salsify\Webhooks;

/**
 * The parts of a webhook that its signature covers.
 *
 * Four headers and the body, and the one thing that is *not* in the request:
 * the endpoint URL. Salsify signs the URL it was configured to call, so a
 * verifier has to supply it from its own configuration. Taking it from the
 * request - from `Host`, from a proxy header, from the request URI - would
 * mean verifying the signature against a value the caller controls, which
 * verifies nothing.
 *
 * The body is the raw bytes. Not a re-encoded array, not a framework's parsed
 * input: the signature covers what arrived, byte for byte, and a JSON document
 * that round-trips through `json_decode`/`json_encode` is a different string
 * the moment a key order or a float changes.
 */
final class SignedRequest
{
    public const TIMESTAMP = 'X-Salsify-Timestamp';

    public const REQUEST_ID = 'X-Salsify-Request-ID';

    public const ORGANIZATION_ID = 'X-Salsify-Organization-ID';

    public const SIGNATURE = 'X-Salsify-Signature-V1';

    public const CERTIFICATE_URL = 'X-Salsify-Cert-Url';

    private function __construct(
        public readonly string $timestamp,
        public readonly string $requestId,
        public readonly string $organizationId,
        public readonly string $signature,
        public readonly string $certificateUrl,
        public readonly string $body,
    ) {}

    /**
     * From a map of headers, however the framework spells them.
     *
     * Header names are case-insensitive by the HTTP specification and every
     * framework spells them differently - `HTTP_X_SALSIFY_TIMESTAMP` in PHP's
     * own superglobal, `x-salsify-timestamp` in PSR-7. So they are matched on
     * a normalised form rather than on a guess about the caller's stack.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function fromHeaders(array $headers, string $body): self
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $key = strtolower(str_replace('_', '-', (string) $name));
            $key = preg_replace('/^http-/', '', $key) ?? $key;
            $normalised[$key] = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
        }

        $read = static function (string $header) use ($normalised): string {
            $value = $normalised[strtolower($header)] ?? '';

            if (trim($value) === '') {
                throw InvalidSignature::missingHeader($header);
            }

            return trim($value);
        };

        return new self(
            $read(self::TIMESTAMP),
            $read(self::REQUEST_ID),
            $read(self::ORGANIZATION_ID),
            $read(self::SIGNATURE),
            $read(self::CERTIFICATE_URL),
            $body,
        );
    }

    /**
     * The string the signature was computed over.
     *
     * Five parts, joined by periods, in this order - and the URL is the one
     * the verifier was configured with, never one the request supplied.
     */
    public function signedString(string $endpointUrl): string
    {
        return implode('.', [
            $this->timestamp,
            $this->requestId,
            $this->organizationId,
            $endpointUrl,
            $this->body,
        ]);
    }

    public function signedAt(): ?int
    {
        return preg_match('/^\d{1,12}$/', $this->timestamp) === 1 ? (int) $this->timestamp : null;
    }

    /** The decoded signature, or null if it is not base64. */
    public function signatureBytes(): ?string
    {
        $decoded = base64_decode($this->signature, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
