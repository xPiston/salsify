<?php

declare(strict_types=1);

namespace Piston\Salsify\Bridge\Symfony;

use Piston\Salsify\Webhooks\SignedRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * A Salsify webhook, read out of a Symfony request.
 *
 * The raw body, because that is what the signature covers.
 */
final class WebhookRequest
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function from(Request $request): SignedRequest
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            // Symfony allows a null in the list for a header sent with no
            // value; the signed request only ever wants the first one.
            $headers[(string) $name] = (string) ($values[0] ?? '');
        }

        return SignedRequest::fromHeaders($headers, (string) $request->getContent());
    }
}
