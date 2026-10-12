<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/** What a PSR-18 client throws when it cannot reach the other end. */
final class TransportFailure extends RuntimeException implements ClientExceptionInterface {}
