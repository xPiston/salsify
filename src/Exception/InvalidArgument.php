<?php

declare(strict_types=1);

namespace Piston\Salsify\Exception;

use InvalidArgumentException;

/**
 * A value this library can tell is wrong before spending a request on it.
 *
 * Which matters more here than in most SDKs: an organization gets ten thousand
 * requests an hour, shared by every integration it runs, and a request that
 * was never going to work still costs one of them.
 */
final class InvalidArgument extends InvalidArgumentException implements SalsifyException
{
    public static function because(string $subject, string $rule, string $given): self
    {
        return new self(sprintf('%s: %s, got "%s".', $subject, $rule, $given));
    }

    public static function token(string $rule): self
    {
        // Never with the value: this one ends up in logs.
        return new self(sprintf('API token: %s.', $rule));
    }
}
