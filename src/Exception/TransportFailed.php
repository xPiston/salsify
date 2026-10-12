<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Salsify could not be reached, or answered something that is not JSON.
 *
 * Kept apart from ApiError because the two deserve opposite treatment: a
 * refusal is an answer and repeating it changes nothing, while this is the
 * absence of one and is the only case where trying again is reasonable - and
 * the only case that did not cost a request against the hour.
 */
final class TransportFailed extends RuntimeException implements SalsifyException
{
    public static function unreachable(ClientExceptionInterface $previous): self
    {
        return new self('Salsify could not be reached: '.$previous->getMessage(), 0, $previous);
    }

    public static function notJson(int $status, string $body): self
    {
        return new self(sprintf(
            'Salsify answered %d with something that is not JSON: %s',
            $status,
            mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : $body,
        ));
    }
}
