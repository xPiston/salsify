<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Exception\RateLimited;
use Piston\Salsify\Exports\EntityType;
use Piston\Salsify\Exports\ExportConfiguration;
use Piston\Salsify\Exports\Format;
use Piston\Salsify\Salsify;
use Piston\Salsify\Tests\Support\FakeHttpClient;

/**
 * The whole export, from the start call to the records.
 *
 * The unit tests each hold one piece still. This one runs the sequence an
 * integration actually runs - start, poll, poll again, download, read - over a
 * single Salsify instance, because the mistakes this SDK is most likely to
 * cause are in the joins: the wrong base path on the second call, a `url`
 * that is an empty string being treated as a file, a rate limit that is only
 * reported for the last request made.
 */
final class ExportLifecycleTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[Test]
    public function test_an_export_is_started_polled_and_read(): void
    {
        $this->file = $this->exportFile([
            '{"salsify:entity_type":"header","salsify:version":"1.0","salsify:organization_id":"s-1234"}',
            '{"salsify:entity_type":"property","salsify:id":"SKU","salsify:data_type":"string","salsify:role":"id"}',
            '{"salsify:entity_type":"property","salsify:id":"Color","salsify:data_type":"enumerated"}',
            '{"salsify:entity_type":"enumerated_value","salsify:property_id":"Color","salsify:id":"red","salsify:name":"Red"}',
            '{"salsify:entity_type":"digital_asset","salsify:id":"asset-1","salsify:url":"https://images.salsify.com/asset-1.jpg"}',
            '{"salsify:entity_type":"record","SKU":"ABC-1","Color":"red","salsify:created_at":"2021-06-21T18:28:02.935Z"}',
            '{"salsify:entity_type":"record","SKU":"ABC-2","Color":"red"}',
        ]);

        $http = FakeHttpClient::replyingInTurn([
            // Start: the id comes back with an empty url, and that empty
            // string is the whole point of ExportRun::isReady().
            ['{"id":"3635065","status":"running","progress":0,"url":"","start_time":"2026-02-11T09:00:00.000Z"}', 200,
                ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '9999', 'x-ratelimit-reset' => '3540']],
            // Still running.
            ['{"id":"3635065","status":"running","progress":42,"url":""}', 200,
                ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '9998', 'x-ratelimit-reset' => '3530']],
            // Done, with a file.
            ['{"id":"3635065","status":"completed","progress":100,"url":"'.$this->file.'","end_time":"2026-02-11T09:04:00.000Z"}', 200,
                ['x-ratelimit-limit' => '10000', 'x-ratelimit-remaining' => '9997', 'x-ratelimit-reset' => '3520']],
        ]);

        $salsify = new Salsify(
            \Piston\Salsify\Configuration\Credentials::of('tok-abc', 's-1234-5678-9012-3456'),
            http: $http,
        );

        $configuration = ExportConfiguration::of(EntityType::Product, Format::JsonLines)
            ->onlyProperties(['SKU', 'Color']);

        $run = $salsify->exports()->start($configuration);

        self::assertSame('3635065', $run->id());
        self::assertFalse($run->isReady(), 'a run whose url is "" has no file');
        self::assertNull($run->url());
        self::assertSame('2026-02-11', $run->startedAt()?->format('Y-m-d'));

        // The start request: unversioned prefix, the configuration wrapped.
        $start = $http->requests[0];
        self::assertSame('POST', $start->getMethod());
        self::assertSame(
            'https://app.salsify.com/api/orgs/s-1234-5678-9012-3456/export_runs',
            (string) $start->getUri(),
        );
        self::assertSame('Bearer tok-abc', $start->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('access_token', (string) $start->getUri());

        /** @var array{configuration: array<string, mixed>} $body */
        $body = json_decode((string) $start->getBody(), true);
        self::assertSame('product', $body['configuration']['entity_type']);
        self::assertSame('jsonl', $body['configuration']['format']);
        self::assertSame("'SKU','Color'", $body['configuration']['properties']);

        // Polling, twice, by the id the start call returned.
        $running = $salsify->exports()->status($run);
        self::assertSame(42, $running->progress());
        self::assertFalse($running->isReady());

        $ready = $salsify->exports()->status($running);
        self::assertTrue($ready->isReady());
        self::assertFalse($ready->hasFailed());
        self::assertSame(100, $ready->progress());

        foreach ([1, 2] as $poll) {
            $request = $http->requests[$poll];
            self::assertSame('GET', $request->getMethod());
            self::assertSame(
                'https://app.salsify.com/api/orgs/s-1234-5678-9012-3456/export_runs/3635065',
                (string) $request->getUri(),
                'a poll stays on the unversioned prefix, like the start call',
            );
            self::assertSame('', (string) $request->getBody());
        }

        // Reading the file: the schema arrives before the records that use it.
        $types = [];
        $records = [];

        foreach ($salsify->exports()->stream($ready) as $line) {
            $types[] = $line->entityType();

            if ($line->isRecord()) {
                $records[] = $line->toRecord();
            }
        }

        self::assertSame(
            ['header', 'property', 'property', 'enumerated_value', 'digital_asset', 'record', 'record'],
            $types,
        );

        self::assertCount(2, $records);
        self::assertSame('ABC-1', $records[0]->property('SKU'));
        self::assertSame('red', $records[0]->property('Color'));
        self::assertSame('2021-06-21', $records[0]->createdAt()?->format('Y-m-d'));
        self::assertArrayNotHasKey(
            'salsify:entity_type',
            $records[0]->toArray(),
            'the line marker is not one of the record\'s properties',
        );

        // Downloading the file is not an API call, so the budget is what the
        // last *API* response said - not "unknown" because a fopen happened.
        self::assertSame(9997, $salsify->rateLimit()->remaining);
        self::assertSame(3, count($http->requests), 'the file is read directly, not through the API client');
    }

    #[Test]
    public function test_a_failed_run_says_why_and_is_never_streamed(): void
    {
        $http = FakeHttpClient::replyingInTurn([
            ['{"id":"99","status":"running","url":""}', 200, []],
            ['{"id":"99","status":"failed","url":"","failure_reason":"The export timed out"}', 200, []],
        ]);

        $salsify = new Salsify(
            \Piston\Salsify\Configuration\Credentials::of('tok', 's-1234-5678-9012-3456'),
            http: $http,
        );

        $run = $salsify->exports()->status($salsify->exports()->start(ExportConfiguration::everything()));

        self::assertTrue($run->hasFailed());
        self::assertSame('The export timed out', $run->failureReason());
        self::assertFalse($run->isReady());

        $this->expectException(\Piston\Salsify\Exception\InvalidArgument::class);
        $this->expectExceptionMessageMatches('/no file yet/');

        iterator_to_array($salsify->exports()->stream($run));
    }

    #[Test]
    public function test_polling_too_fast_is_a_rate_limit_the_caller_has_to_answer(): void
    {
        $http = FakeHttpClient::replyingInTurn([
            ['{"id":"7","status":"running","url":""}', 200, ['x-ratelimit-remaining' => '1', 'x-ratelimit-limit' => '10000']],
            ['{"errors":["Rate limit exceeded"]}', 429, ['x-ratelimit-remaining' => '0', 'x-ratelimit-limit' => '10000', 'x-ratelimit-reset' => '120']],
        ]);

        $salsify = new Salsify(
            \Piston\Salsify\Configuration\Credentials::of('tok', 's-1234-5678-9012-3456'),
            http: $http,
        );

        $run = $salsify->exports()->start(ExportConfiguration::everything());

        self::assertTrue($salsify->rateLimit()->isRunningOut(), 'one request left of ten thousand is a warning');

        try {
            $salsify->exports()->status($run);
            self::fail('a 429 must not be returned as a result');
        } catch (RateLimited $e) {
            self::assertSame(429, $e->status);
            self::assertSame(['Rate limit exceeded'], $e->messages());
            self::assertSame(120, $e->rateLimit?->resetsIn);
            self::assertNotNull($e->resetsAt());
        }

        // The SDK did not quietly try again on the caller's behalf.
        self::assertCount(2, $http->requests);
    }

    /** @param list<string> $lines */
    private function exportFile(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'salsify-export-');
        self::assertNotFalse($path);
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }
}
