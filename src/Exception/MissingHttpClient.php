<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use LogicException;

/** Everything here talks to Salsify, so the client is not optional. */
final class MissingHttpClient extends LogicException implements SalsifyException
{
    public static function create(): self
    {
        return new self(
            'This SDK needs a PSR-18 client. Pass one to Salsify::__construct(), or install '
            .'php-http/discovery together with a client (symfony/http-client, guzzlehttp/guzzle) and it will be found.',
        );
    }
}
