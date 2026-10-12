<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Exports;

use PHPUnit\Framework\TestCase;
use Piston\Salsify\Exception\InvalidArgument;
use Piston\Salsify\Exports\EntityType;
use Piston\Salsify\Exports\ExportConfiguration;
use Piston\Salsify\Exports\ExportRun;
use Piston\Salsify\Exports\Format;

final class ExportTest extends TestCase
{
    public function test_a_configuration_is_the_body_salsify_expects(): void
    {
        $configuration = ExportConfiguration::of(EntityType::Product, Format::Csv);

        $this->assertSame(
            ['configuration' => ['entity_type' => 'product', 'format' => 'csv']],
            $configuration->toRequestBody(),
        );
    }

    public function test_the_defaults_are_salsifys(): void
    {
        $this->assertSame(Format::Json, ExportConfiguration::of(EntityType::All)->format());
        $this->assertSame(Format::Xlsx, ExportConfiguration::of(EntityType::Product)->format());
    }

    /** Salsify's rule, enforced before the request rather than after a 400. */
    public function test_an_export_of_everything_cannot_be_a_spreadsheet(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('can only be json or jsonl');

        ExportConfiguration::of(EntityType::All, Format::Xlsx);
    }

    public function test_an_attribute_export_cannot_be_json(): void
    {
        $this->expectException(InvalidArgument::class);

        ExportConfiguration::of(EntityType::Attribute, Format::Json);
    }

    public function test_the_shape_for_mirroring_an_organization(): void
    {
        $configuration = ExportConfiguration::everything();

        $this->assertSame([
            'entity_type' => 'all',
            'format' => 'jsonl',
            'compress' => true,
        ], $configuration->toArray());
        $this->assertTrue($configuration->isCompressed());
    }

    /**
     * Salsify wants the property list as one string of single-quoted,
     * comma-separated values - a format nobody guesses right the first time.
     */
    public function test_restricting_the_properties_builds_the_string_salsify_wants(): void
    {
        $configuration = ExportConfiguration::of(EntityType::Product, Format::Csv)
            ->onlyProperties(['Product ID', 'Product Name', 'UPC']);

        $this->assertSame("'Product ID','Product Name','UPC'", $configuration->toArray()['properties']);
        $this->assertFalse($configuration->toArray()['include_all_columns']);
    }

    public function test_a_property_id_containing_a_quote_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('cannot contain a single quote');

        ExportConfiguration::of(EntityType::Product)->onlyProperties(["Bob's Property"]);
    }

    public function test_an_empty_property_list_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        ExportConfiguration::of(EntityType::Product)->onlyProperties([]);
    }

    public function test_it_is_immutable(): void
    {
        $base = ExportConfiguration::of(EntityType::Product);
        $filtered = $base->filtered("='Brand':'Acme'");

        $this->assertArrayNotHasKey('filter', $base->toArray());
        $this->assertSame("='Brand':'Acme'", $filtered->toArray()['filter']);
    }

    public function test_an_option_this_library_has_no_method_for(): void
    {
        $configuration = ExportConfiguration::of(EntityType::Product)->with('sort_order', 'ascending');

        $this->assertSame('ascending', $configuration->toArray()['sort_order']);
    }

    /** The documented response to a start request, verbatim. */
    public function test_it_reads_a_run_that_has_just_started(): void
    {
        $run = ExportRun::fromArray([
            'id' => 19350473,
            'status' => 'running',
            'start_time' => '2019-12-06T19:38:11.753Z',
            'end_time' => null,
            'duration' => 0.044245656,
            'url' => '',
            'progress' => 10,
            'failure_reason' => null,
            'estimated_time_remaining' => '',
        ]);

        $this->assertSame('19350473', $run->id());
        $this->assertSame('running', $run->status());
        $this->assertSame(10, $run->progress());
        $this->assertSame('2019-12-06 19:38:11', $run->startedAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($run->endedAt());
        $this->assertFalse($run->hasFailed());
    }

    /**
     * The detail worth encoding: `url` is an empty string until the file
     * exists, so `isset($run['url'])` is true and downloads nothing.
     */
    public function test_an_empty_url_is_not_a_url(): void
    {
        $running = ExportRun::fromArray(['status' => 'running', 'url' => '']);
        $done = ExportRun::fromArray(['status' => 'completed', 'url' => 'https://files.salsify.test/export.jsonl.gz']);

        $this->assertFalse($running->isReady());
        $this->assertNull($running->url());

        $this->assertTrue($done->isReady());
        $this->assertSame('https://files.salsify.test/export.jsonl.gz', $done->url());
    }

    public function test_a_failed_run_says_why(): void
    {
        $run = ExportRun::fromArray(['status' => 'failed', 'failure_reason' => 'the filter is not valid']);

        $this->assertTrue($run->hasFailed());
        $this->assertSame('the filter is not valid', $run->failureReason());
        $this->assertFalse($run->isReady());
    }
}
