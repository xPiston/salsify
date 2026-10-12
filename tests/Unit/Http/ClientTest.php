<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Http;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Configuration\Organization;
use Piston\Salsify\Exception\ApiError;
use Piston\Salsify\Exception\NotFound;
use Piston\Salsify\Exception\RateLimited;
use Piston\Salsify\Exception\TransportFailed;
use Piston\Salsify\Exception\Unauthorized;
use Piston\Salsify\Http\Client;
use Piston\Salsify\Http\Request;
use Piston\Salsify\Tests\Support\FakeHttpClient;
use Piston\Salsify\Tests\Support\TransportFailure;

final class ClientTest extends TestCase
{
    private const ORG = 's-1234-5678-9012-3456';

    public function test_it_sends_the_token_in_the_header_and_never_in_the_url(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->client($http)->send(Request::get('records/42'));

        $request = $http->lastRequest();

        $this->assertSame('Bearer a-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));

        // Salsify supports ?access_token= and its own documentation explains
        // why not to use it: URLs are logged by proxies and error trackers.
        $this->assertStringNotContainsString('access_token', (string) $request->getUri());
        $this->assertStringNotContainsString('a-token', (string) $request->getUri());
    }

    /**
     * The two base paths are Salsify's, not this library's: records are
     * versioned, export runs are not.
     */
    public function test_it_knows_which_calls_are_versioned(): void
    {
        $http = FakeHttpClient::replyingInTurn([['{}', 200, []], ['{}', 200, []]]);
        $client = $this->client($http);

        $client->send(Request::get('records/42'));
        $this->assertSame(
            'https://app.salsify.com/api/v1/orgs/'.self::ORG.'/records/42',
            (string) $http->lastRequest()->getUri(),
        );

        $client->send(Request::post('export_runs', ['a' => 'b'])->unversioned());
        $this->assertSame(
            'https://app.salsify.com/api/orgs/'.self::ORG.'/export_runs',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_it_sends_a_json_body(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->client($http)->send(Request::post('records', ['SKU' => '42', 'Name' => 'Big TV']));

        $this->assertSame('application/json', $http->lastRequest()->getHeaderLine('Content-Type'));
        $this->assertSame('{"SKU":"42","Name":"Big TV"}', (string) $http->lastRequest()->getBody());
    }

    public function test_a_get_carries_no_body_and_no_content_type(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->client($http)->send(Request::get('records/42'));

        $this->assertSame('', (string) $http->lastRequest()->getBody());
        $this->assertSame('', $http->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function test_query_parameters_are_encoded(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->client($http)->send(Request::get('records', ['filter' => "='Brand':'Acme Ltd'"]));

        $this->assertStringContainsString('filter=%3D%27Brand%27%3A%27Acme%20Ltd%27', (string) $http->lastRequest()->getUri());
    }

    /** The budget travels with every reply, because that is when it is read. */
    public function test_it_reads_the_rate_limit_from_every_response(): void
    {
        $http = FakeHttpClient::replyingWithBudget('{}', remaining: 4211, limit: 10000, resets: 1234);

        $reply = $this->client($http)->send(Request::get('records/42'));

        $this->assertSame(10000, $reply->rateLimit->limit);
        $this->assertSame(4211, $reply->rateLimit->remaining);
        $this->assertSame(1234, $reply->rateLimit->resetsIn);
        $this->assertTrue($reply->rateLimit->isKnown());
        $this->assertFalse($reply->rateLimit->isRunningOut());
    }

    public function test_the_client_remembers_the_last_rate_limit(): void
    {
        $http = FakeHttpClient::replyingWithBudget('{}', remaining: 120);
        $client = $this->client($http);

        $this->assertFalse($client->rateLimit()->isKnown(), 'nothing is known before the first call');

        $client->send(Request::get('records/42'));

        $this->assertSame(120, $client->rateLimit()->remaining);
        $this->assertTrue($client->rateLimit()->isRunningOut(), '120 of 10000 is the moment to slow down');
    }

    /**
     * 429 is not retried here, on purpose: the budget belongs to the whole
     * organization, and a retry loop inside an SDK is multiplied by every job
     * an application runs.
     */
    public function test_a_429_is_an_exception_carrying_the_reset(): void
    {
        $http = FakeHttpClient::replying('{"error":"rate limit exceeded"}', 429, [
            'x-ratelimit-limit' => '10000',
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => '900',
        ]);

        try {
            $this->client($http)->send(Request::get('records/42'));
            $this->fail('a 429 should have been thrown');
        } catch (RateLimited $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(900, $e->rateLimit?->resetsIn);
            $this->assertStringContainsString('resets in 900 seconds', $e->getMessage());
            $this->assertSame(['rate limit exceeded'], $e->messages());
            $this->assertCount(1, $http->requests, 'it must not have tried again');
        }
    }

    public function test_the_reset_can_be_read_as_a_moment(): void
    {
        $http = FakeHttpClient::replying('{}', 429, ['x-ratelimit-reset' => '600']);

        try {
            $this->client($http)->send(Request::get('records/42'));
            $this->fail('expected a 429');
        } catch (RateLimited $e) {
            $now = new DateTimeImmutable('2026-10-12 16:45:00');

            $this->assertSame('2026-10-12 16:55:00', $e->resetsAt($now)?->format('Y-m-d H:i:s'));
        }
    }

    public function test_a_401_names_the_three_things_that_cause_it(): void
    {
        $http = FakeHttpClient::replying('{"error":"unauthorized"}', 401);

        try {
            $this->client($http)->send(Request::get('records/42'));
            $this->fail('expected a 401');
        } catch (Unauthorized $e) {
            $this->assertStringContainsString('Check the token', $e->getMessage());
            $this->assertStringContainsString(self::ORG, $e->getMessage());
            // API access is a licence option at Salsify, which is a real and
            // confusing cause of 403.
            $this->assertStringContainsString('licence includes API access', $e->getMessage());
        }
    }

    public function test_a_404_is_its_own_exception(): void
    {
        $http = FakeHttpClient::replying('{}', 404);

        $this->expectException(NotFound::class);

        $this->client($http)->send(Request::get('records/nope'));
    }

    /**
     * Salsify's 400 for an ambiguous organization lists the organizations you
     * belong to. An SDK that reduced that to "request failed" would be
     * throwing away the most useful thing in the response.
     */
    public function test_an_error_body_is_kept_whole(): void
    {
        $body = [
            'error' => ['You are not able to utilize the route ... since you belong to multiple organizations.'],
            'organizations' => [
                ['name' => "Angie's Sandbox", 'system_id' => 's-9c9999-9999-9999-9999'],
                ['name' => 'Wildflower', 'system_id' => 's-9999-9999-9999-d0999999dc'],
            ],
        ];

        $http = FakeHttpClient::replying((string) json_encode($body), 400);

        try {
            $this->client($http)->send(Request::get('records/42'));
            $this->fail('expected a 400');
        } catch (ApiError $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame($body, $e->body);
            $this->assertCount(2, $e->body['organizations']);
            $this->assertStringContainsString('multiple organizations', $e->getMessage());
        }
    }

    public function test_a_transport_failure_is_not_an_api_error(): void
    {
        $http = FakeHttpClient::failing(new TransportFailure('Connection timed out'));

        $this->expectException(TransportFailed::class);
        $this->expectExceptionMessage('could not be reached: Connection timed out');

        $this->client($http)->send(Request::get('records/42'));
    }

    public function test_a_body_that_is_not_json_is_not_an_answer(): void
    {
        $http = FakeHttpClient::replying('<html>Bad Gateway</html>', 502);

        $this->expectException(TransportFailed::class);
        $this->expectExceptionMessage('is not JSON');

        $this->client($http)->send(Request::get('records/42'));
    }

    public function test_an_empty_body_is_an_empty_reply_rather_than_a_failure(): void
    {
        // What a DELETE answers.
        $http = FakeHttpClient::replying('', 204);

        $reply = $this->client($http)->send(Request::delete('records/42'));

        $this->assertSame(204, $reply->status);
        $this->assertSame([], $reply->body);
    }

    private function client(FakeHttpClient $http): Client
    {
        $factory = new Psr17Factory;
        $credentials = Credentials::of('a-token', Organization::of(self::ORG));

        return new Client($credentials, new Endpoint($credentials->organization), $http, $factory, $factory);
    }
}
