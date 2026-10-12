<?php

declare(strict_types=1);

namespace Piston\Salsify\Http;

use JsonException;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Exception\ApiError;
use Piston\Salsify\Exception\NotFound;
use Piston\Salsify\Exception\RateLimited;
use Piston\Salsify\Exception\TransportFailed;
use Piston\Salsify\Exception\Unauthorized;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The one place that talks to Salsify.
 *
 * Everything above it builds a Request and reads a Reply; everything about
 * authentication, JSON, status codes and the rate limit lives here.
 *
 * Three decisions worth stating, because each is a thing this client
 * deliberately does *not* do.
 *
 * **It never retries.** Not a 429, not a 500. An organization's ten thousand
 * requests an hour are shared by every integration it runs, and a retry loop
 * hidden inside an SDK is multiplied by every job an application starts -
 * which is how a rate limit goes from a slow afternoon to a lost one. The
 * exception carries the seconds until the reset; the decision is the
 * application's.
 *
 * **It never sends the token in the URL.** Salsify supports `?access_token=`
 * and its own documentation explains why not to: URLs are logged by proxies,
 * by servers, by error trackers. The header costs nothing.
 *
 * **It does not pretend an error is a result.** A 4xx is an exception carrying
 * the status and the decoded body, because Salsify's error bodies are useful -
 * a 400 for an ambiguous organization lists the organizations you belong to -
 * and the most common mistake an SDK makes with them is to throw them away.
 */
final class Client
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Endpoint $endpoint,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    private ?RateLimit $lastRateLimit = null;

    /** What the most recent response said about the budget. */
    public function rateLimit(): RateLimit
    {
        return $this->lastRateLimit ?? RateLimit::unknown();
    }

    public function send(Request $request): Reply
    {
        $url = $request->versioned
            ? $this->endpoint->versioned($request->path)
            : $this->endpoint->unversioned($request->path);

        if ($request->query !== []) {
            $url .= '?'.http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        }

        $http = $this->requests->createRequest($request->method, $url)
            ->withHeader('Authorization', $this->credentials->authorizationHeader())
            ->withHeader('Accept', 'application/json');

        if ($request->body !== null) {
            $http = $http
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($this->encode($request->body)));
        }

        try {
            $response = $this->http->sendRequest($http);
        } catch (ClientExceptionInterface $e) {
            throw TransportFailed::unreachable($e);
        }

        $rateLimit = RateLimit::fromResponse($response);
        $this->lastRateLimit = $rateLimit;

        $status = $response->getStatusCode();
        $body = $this->decode($response);

        if ($status >= 200 && $status < 300) {
            return new Reply($status, $body, $rateLimit);
        }

        throw $this->error($status, $body, $request, $rateLimit);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function error(int $status, array $body, Request $request, RateLimit $rateLimit): ApiError
    {
        $path = $request->path;

        return match (true) {
            $status === 429 => RateLimited::with($rateLimit, $body, $request->method, $path),
            $status === 401 || $status === 403 => new Unauthorized(
                $status,
                $body,
                $request->method,
                $path,
                sprintf(
                    '%s %s answered %d. Check the token, that it belongs to organization %s, '
                    .'and that the organization\'s licence includes API access.',
                    $request->method,
                    $path,
                    $status,
                    $this->endpoint->organization()->id(),
                ),
            ),
            $status === 404 => new NotFound($status, $body, $request->method, $path, sprintf('%s %s answered 404', $request->method, $path)),
            default => ApiError::from($status, $body, $request->method, $path),
        };
    }

    /** @param array<string, mixed> $body */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw TransportFailed::notJson(0, 'the request body could not be encoded: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $contents = (string) $response->getBody();

        if (trim($contents) === '') {
            // 204, and the DELETE endpoints.
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TransportFailed::notJson($response->getStatusCode(), $contents);
        }

        if (! is_array($decoded)) {
            throw TransportFailed::notJson($response->getStatusCode(), $contents);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
