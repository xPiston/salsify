<?php

declare(strict_types=1);

namespace Piston\Salsify\Bridge\Laravel;

use Illuminate\Http\Request;
use Piston\Salsify\Webhooks\SignedRequest;

/**
 * A Salsify webhook, read out of a Laravel request.
 *
 * `$request->getContent()` and not `$request->all()`: the signature covers the
 * raw body, byte for byte. A body that has been through `json_decode` and back
 * is a different string the moment a key order, a float or an escape changes -
 * and it will verify correctly in testing and fail on the first payload that
 * happens to differ.
 */
final class WebhookRequest
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function from(Request $request): SignedRequest
    {
        /** @var array<string, list<string>> $headers */
        $headers = $request->headers->all();

        return SignedRequest::fromHeaders($headers, $request->getContent());
    }
}
