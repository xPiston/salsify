<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Entity\Record;
use Piston\Salsify\Exception\InvalidArgument;
use Piston\Salsify\Exports\EntityType;
use Piston\Salsify\Exports\ExportConfiguration;
use Piston\Salsify\Exports\ExportRun;
use Piston\Salsify\Exports\Format;
use Piston\Salsify\Salsify;
use Piston\Salsify\Tests\Support\FakeHttpClient;
use Piston\Salsify\Webhooks\SignatureVerifier;

final class SalsifyTest extends TestCase
{
    private const ORG = 's-1234-5678-9012-3456';

    public function test_it_fetches_a_record(): void
    {
        $http = FakeHttpClient::replyingWithBudget(
            '{"salsify:id":"3635065","Display Name":"40 in. TV","salsify:system_id":"s-abc"}',
            remaining: 9998,
        );

        $record = $this->salsify($http)->records()->get('3635065');

        $this->assertInstanceOf(Record::class, $record);
        $this->assertSame('40 in. TV', $record->property('Display Name'));
        $this->assertSame('GET', $http->lastRequest()->getMethod());
        $this->assertSame(
            'https://app.salsify.com/api/v1/orgs/'.self::ORG.'/records/3635065',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_a_record_id_is_escaped_in_the_path(): void
    {
        $http = FakeHttpClient::replying('{}');

        $this->salsify($http)->records()->get('CMD/2026 #42');

        $this->assertStringEndsWith('/records/CMD%2F2026%20%2342', (string) $http->lastRequest()->getUri());
    }

    /**
     * Salsify only modifies the properties a request mentions, so an update
     * is the two properties that moved - not the whole record read back.
     */
    public function test_an_update_sends_only_what_changes(): void
    {
        $http = FakeHttpClient::replying('{"salsify:id":"3635065"}');

        $this->salsify($http)->records()->update('3635065', ['Colour' => 'Purple']);

        $this->assertSame('PUT', $http->lastRequest()->getMethod());
        $this->assertSame('{"Colour":"Purple"}', (string) $http->lastRequest()->getBody());
    }

    public function test_it_creates_a_record(): void
    {
        $http = FakeHttpClient::replying('{"salsify:id":"3635065"}');

        $this->salsify($http)->records()->create(Record::of('SKU', '3635065', ['Display Name' => '40 in. TV']));

        $this->assertSame('POST', $http->lastRequest()->getMethod());
        $this->assertStringEndsWith('/records', (string) $http->lastRequest()->getUri());
        $this->assertSame('{"SKU":"3635065","Display Name":"40 in. TV"}', (string) $http->lastRequest()->getBody());
    }

    public function test_it_deletes_a_record(): void
    {
        $http = FakeHttpClient::replying('', 204);

        $this->salsify($http)->records()->delete('3635065');

        $this->assertSame('DELETE', $http->lastRequest()->getMethod());
    }

    /** Export runs go to the unversioned path. */
    public function test_it_starts_an_export(): void
    {
        $http = FakeHttpClient::replying('{"id":19350473,"status":"running","url":"","progress":0}');

        $run = $this->salsify($http)->exports()->start(ExportConfiguration::everything());

        $this->assertSame('19350473', $run->id());
        $this->assertFalse($run->isReady());
        $this->assertSame(
            'https://app.salsify.com/api/orgs/'.self::ORG.'/export_runs',
            (string) $http->lastRequest()->getUri(),
        );
        $this->assertSame(
            '{"configuration":{"entity_type":"all","format":"jsonl","compress":true}}',
            (string) $http->lastRequest()->getBody(),
        );
    }

    public function test_it_asks_how_an_export_is_going(): void
    {
        $http = FakeHttpClient::replying('{"id":19350473,"status":"completed","url":"https://files.test/e.jsonl"}');

        $run = $this->salsify($http)->exports()->status('19350473');

        $this->assertTrue($run->isReady());
        $this->assertSame(
            'https://app.salsify.com/api/orgs/'.self::ORG.'/export_runs/19350473',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_streaming_a_run_with_no_file_says_what_is_wrong(): void
    {
        $run = ExportRun::fromArray(['id' => '1', 'status' => 'running', 'url' => '']);

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('its url is empty until the run completes');

        iterator_to_array($this->salsify(FakeHttpClient::replying('{}'))->exports()->stream($run));
    }

    public function test_it_starts_an_import(): void
    {
        $http = FakeHttpClient::replying('{"id":"run-1","status":"running"}');

        $run = $this->salsify($http)->imports()->start('import-42');

        $this->assertSame('run-1', $run->id());
        $this->assertTrue($run->isRunning());
        $this->assertSame(
            'https://app.salsify.com/api/orgs/'.self::ORG.'/imports/import-42/runs',
            (string) $http->lastRequest()->getUri(),
        );
    }

    public function test_the_last_rate_limit_is_readable_from_the_facade(): void
    {
        $http = FakeHttpClient::replyingWithBudget('{}', remaining: 300);
        $salsify = $this->salsify($http);

        $salsify->records()->get('1');

        $this->assertSame(300, $salsify->rateLimit()->remaining);
        $this->assertTrue($salsify->rateLimit()->isRunningOut());
        $this->assertStringContainsString('300 of 10000 requests left', (string) $salsify->rateLimit());
    }

    public function test_it_builds_a_webhook_verifier_for_a_given_endpoint(): void
    {
        $verifier = $this->salsify(FakeHttpClient::replying('{}'))->webhooks('https://shop.test/hooks/salsify');

        $this->assertInstanceOf(SignatureVerifier::class, $verifier);
    }

    public function test_the_shortcut_constructor(): void
    {
        $salsify = Salsify::with('a-token', self::ORG);

        $this->assertSame(self::ORG, $salsify->credentials->organization->id());
    }

    public function test_the_export_formats_are_checked_before_a_request_is_spent(): void
    {
        $http = FakeHttpClient::replying('{}');

        try {
            $this->salsify($http)->exports()->start(ExportConfiguration::of(EntityType::All, Format::Csv));
            $this->fail('an impossible format should have been refused');
        } catch (InvalidArgument) {
            $this->assertSame([], $http->requests, 'and no request should have been made');
        }
    }

    private function salsify(FakeHttpClient $http): Salsify
    {
        $factory = new Psr17Factory;

        return new Salsify(
            \Piston\Salsify\Configuration\Credentials::of('a-token', self::ORG),
            null,
            $http,
            $factory,
            $factory,
        );
    }
}
