<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Entity\Record;
use Piston\Salsify\Exception\NotFound;
use Piston\Salsify\Exception\Unauthorized;
use Piston\Salsify\Salsify;
use Piston\Salsify\Tests\Support\FakeHttpClient;

/**
 * One record, created, read, changed and deleted, through the facade.
 *
 * Four calls over one instance, with every request asserted in full. What this
 * catches that the unit tests cannot: a path that is right for a GET and wrong
 * for a PUT, a header that is only set when there is a body, an id that is
 * interpolated instead of encoded, and a rate limit that stops being reported
 * after the first call.
 */
final class RecordLifecycleTest extends TestCase
{
    private const ORGANIZATION = 's-1234-5678-9012-3456';

    private const BASE = 'https://app.salsify.com/api/v1/orgs/s-1234-5678-9012-3456/records';

    #[Test]
    public function test_a_record_is_created_read_updated_and_deleted(): void
    {
        $created = '{"SKU":"ABC-1","Brand":"Acme","salsify:id":"ABC-1","salsify:system_id":"s-9","salsify:version":1,'
            .'"salsify:created_at":"2026-02-11T09:00:00.000Z"}';

        $http = FakeHttpClient::replyingInTurn([
            [$created, 201, ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '9000', 'x-ratelimit-reset' => '600']],
            [$created, 200, ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '8999', 'x-ratelimit-reset' => '599']],
            ['{"SKU":"ABC-1","Brand":"Acme","Color":"Red","salsify:id":"ABC-1","salsify:version":2}', 200,
                ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '8998', 'x-ratelimit-reset' => '598']],
            ['', 204, ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '8997', 'x-ratelimit-reset' => '597']],
        ]);

        $records = (new Salsify(Credentials::of('tok-abc', self::ORGANIZATION), http: $http))->records();

        // Create: the id lives in the property that holds the ID role.
        $record = $records->create(Record::of('SKU', 'ABC-1', ['Brand' => 'Acme']));

        self::assertSame('ABC-1', $record->id());
        self::assertSame('s-9', $record->systemId());
        self::assertSame(1, $record->version());
        self::assertSame('2026-02-11', $record->createdAt()?->format('Y-m-d'));

        $create = $http->requests[0];
        self::assertSame('POST', $create->getMethod());
        self::assertSame(self::BASE, (string) $create->getUri());
        self::assertSame('application/json', $create->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $create->getHeaderLine('Accept'));
        self::assertSame(['SKU' => 'ABC-1', 'Brand' => 'Acme'], json_decode((string) $create->getBody(), true));

        // Read.
        $fetched = $records->get('ABC-1');
        self::assertSame('Acme', $fetched->property('Brand'));

        $get = $http->requests[1];
        self::assertSame('GET', $get->getMethod());
        self::assertSame(self::BASE.'/ABC-1', (string) $get->getUri());
        self::assertSame('', (string) $get->getBody());
        self::assertFalse($get->hasHeader('Content-Type'), 'a GET carries no body and so declares no type');

        // Update: only what moved.
        $updated = $records->update('ABC-1', ['Color' => 'Red']);
        self::assertSame('Red', $updated->property('Color'));
        self::assertSame(2, $updated->version());

        $put = $http->requests[2];
        self::assertSame('PUT', $put->getMethod());
        self::assertSame(self::BASE.'/ABC-1', (string) $put->getUri());
        self::assertSame(['Color' => 'Red'], json_decode((string) $put->getBody(), true));

        // Delete: a 204 with an empty body is not a parse error.
        $records->delete('ABC-1');

        $delete = $http->requests[3];
        self::assertSame('DELETE', $delete->getMethod());
        self::assertSame(self::BASE.'/ABC-1', (string) $delete->getUri());

        // Every call carried the token in the header and never in the URL.
        foreach ($http->requests as $request) {
            self::assertSame('Bearer tok-abc', $request->getHeaderLine('Authorization'));
            self::assertStringNotContainsString('access_token', (string) $request->getUri());
        }
    }

    #[Test]
    public function test_an_id_with_a_slash_in_it_stays_one_path_segment(): void
    {
        $http = FakeHttpClient::replying('{"salsify:id":"A/B"}');

        (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $http))
            ->records()
            ->get('A/B 1+2');

        self::assertSame(
            self::BASE.'/A%2FB%201%2B2',
            (string) $http->lastRequest()->getUri(),
            'an id is encoded, not interpolated: a slash in it must not become a path',
        );
    }

    #[Test]
    public function test_a_missing_record_and_a_refused_token_are_different_exceptions(): void
    {
        $missing = FakeHttpClient::replying('{"errors":["Record not found"]}', 404);

        try {
            (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $missing))->records()->get('nope');
            self::fail('a 404 must be an exception');
        } catch (NotFound $e) {
            self::assertSame(404, $e->status);
            self::assertSame(['Record not found'], $e->messages());
        }

        $refused = FakeHttpClient::replying('{"errors":["Unauthorized"]}', 401);

        try {
            (new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $refused))->records()->get('ABC-1');
            self::fail('a 401 must be an exception');
        } catch (Unauthorized $e) {
            self::assertSame(401, $e->status);
            // The message names the licence, because that is the answer often
            // enough to be worth saying before somebody re-reads the token.
            self::assertStringContainsString('licence includes API access', $e->getMessage());
            self::assertStringContainsString(self::ORGANIZATION, $e->getMessage());
        }
    }

    #[Test]
    public function test_the_legacy_products_path_is_the_same_operations(): void
    {
        $http = FakeHttpClient::replying('{"salsify:id":"ABC-1"}');

        $salsify = new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $http);
        $salsify->records()->products()->get('ABC-1');

        self::assertSame(self::BASE.'/ABC-1', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function test_the_budget_reported_is_the_one_from_the_last_call(): void
    {
        $http = FakeHttpClient::replyingInTurn([
            ['{"salsify:id":"1"}', 200, ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '5000', 'x-ratelimit-reset' => '900']],
            ['{"salsify:id":"2"}', 200, ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '400', 'x-ratelimit-reset' => '880']],
        ]);

        $salsify = new Salsify(Credentials::of('tok', self::ORGANIZATION), http: $http);

        self::assertFalse($salsify->rateLimit()->isKnown(), 'nothing is known before the first call');

        $salsify->records()->get('1');
        self::assertSame(5000, $salsify->rateLimit()->remaining);
        self::assertFalse($salsify->rateLimit()->isRunningOut());

        // A second RecordClient, same underlying HTTP client: the budget is
        // the organization's, not the client object's.
        $salsify->records()->get('2');
        self::assertSame(400, $salsify->rateLimit()->remaining);
        self::assertTrue($salsify->rateLimit()->isRunningOut());
        self::assertFalse($salsify->rateLimit()->isExhausted());
        self::assertSame('400 of 10000 requests left, resetting in 880s', (string) $salsify->rateLimit());
    }
}
