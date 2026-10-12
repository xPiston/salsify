<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers from a queue and keeps what it was asked.
 *
 * Salsify has no sandbox and API access is a licence option, so there is no
 * honest way to test this SDK against the real thing without a customer's
 * credentials. This is the substitute: every request is asserted on in full -
 * method, URL, headers, body - and the responses are the ones Salsify's own
 * documentation prints, including its rate limit headers and its error
 * bodies.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /**
     * One reply, with whatever headers Salsify would have sent.
     *
     * @param  array<string, string>  $headers
     */
    public static function replying(string $body, int $status = 200, array $headers = []): self
    {
        $client = new self;
        $client->queue[] = new Response($status, ['Content-Type' => 'application/json', ...$headers], $body);

        return $client;
    }

    /** The three rate-limit headers Salsify puts on every response. */
    public static function replyingWithBudget(string $body, int $remaining, int $limit = 10000, int $resets = 1800): self
    {
        return self::replying($body, 200, [
            'x-ratelimit-limit' => (string) $limit,
            'x-ratelimit-remaining' => (string) $remaining,
            'x-ratelimit-reset' => (string) $resets,
        ]);
    }

    /**
     * Several replies, in order, for a flow that makes more than one call.
     *
     * @param  list<array{0: string, 1: int, 2: array<string, string>}>  $replies
     */
    public static function replyingInTurn(array $replies): self
    {
        $client = new self;

        foreach ($replies as [$body, $status, $headers]) {
            $client->queue[] = new Response($status, ['Content-Type' => 'application/json', ...$headers], $body);
        }

        return $client;
    }

    public static function failing(ClientExceptionInterface $exception): self
    {
        $client = new self;
        $client->queue[] = $exception;

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next ?? new Response(200, [], '{}');
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * The JSON body, decoded.
     *
     * @return array<string, mixed>
     */
    public function lastBodyFields(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }
}
