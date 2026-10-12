<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Exports;

use PHPUnit\Framework\TestCase;
use Piston\Salsify\Entity\Record;
use Piston\Salsify\Exception\InvalidArgument;
use Piston\Salsify\Exception\TransportFailed;
use Piston\Salsify\Exports\JsonLines;
use Piston\Salsify\Exports\Line;

final class JsonLinesTest extends TestCase
{
    /** The example printed in Salsify's export documentation, verbatim. */
    private const DOCUMENTED_EXPORT = <<<'JSONL'
        { "salsify:entity_type": "header", "version": "1", "scope": "all" }
        { "salsify:entity_type": "property", "salsify:id": "SKU", "salsify:role": "record_id" }
        { "salsify:entity_type": "property", "salsify:id": "Display Name", "salsify:role": "record_name" }
        { "salsify:entity_type": "property", "salsify:id": "Type", "salsify:data_type": "enumerated" }
        { "salsify:entity_type": "enumerated_value", "salsify:id": "Television", "salsify:property_id": "Type" }
        { "salsify:entity_type": "digital_asset", "salsify:id": "3635065-FRONT-VIEW", "salsify:name": "40in TV Front View", "salsify:url": "http://example.com/3635065-FRONT-VIEW.png" }
        { "salsify:entity_type": "record", "SKU": "3635065", "Display Name": "40 in. TV", "Type": "Television" }
        JSONL;

    public function test_it_reads_the_documented_export(): void
    {
        $lines = iterator_to_array(JsonLines::read($this->stream(self::DOCUMENTED_EXPORT)));

        $this->assertCount(7, $lines);
        $this->assertSame(
            ['header', 'property', 'property', 'property', 'enumerated_value', 'digital_asset', 'record'],
            array_map(static fn (Line $line): ?string => $line->entityType(), $lines),
        );

        $this->assertTrue($lines[0]->isHeader());
        $this->assertTrue($lines[1]->isProperty());
        $this->assertSame('SKU', $lines[1]->id());
        $this->assertTrue($lines[4]->isEnumeratedValue());
        $this->assertTrue($lines[5]->isDigitalAsset());
        $this->assertSame('http://example.com/3635065-FRONT-VIEW.png', $lines[5]->get('salsify:url'));
        $this->assertTrue($lines[6]->isRecord());
    }

    /**
     * The file tells a schema before the rows that use it, which is the shape
     * an integration mirroring an organization reads in one pass.
     */
    public function test_a_record_line_becomes_a_record_without_its_marker(): void
    {
        $records = iterator_to_array(JsonLines::records(JsonLines::read($this->stream(self::DOCUMENTED_EXPORT))));

        $this->assertCount(1, $records);
        $this->assertInstanceOf(Record::class, $records[0]);
        $this->assertSame('3635065', $records[0]->property('SKU'));
        $this->assertSame('40 in. TV', $records[0]->property('Display Name'));
        $this->assertFalse($records[0]->has('salsify:entity_type'), 'the marker is not a property of the record');
    }

    /**
     * Salsify adds to this format. A reader that skipped what it did not
     * recognise would silently lose data from a newer organization than
     * itself - for a mirror, the worst possible failure.
     */
    public function test_an_entity_type_nobody_has_seen_is_kept(): void
    {
        $lines = iterator_to_array(JsonLines::read($this->stream(
            '{"salsify:entity_type":"something_new","salsify:id":"x","weight":42}',
        )));

        $this->assertCount(1, $lines);
        $this->assertSame('something_new', $lines[0]->entityType());
        $this->assertSame(42, $lines[0]->get('weight'));
        $this->assertFalse($lines[0]->isRecord());
    }

    public function test_blank_lines_are_skipped(): void
    {
        $lines = iterator_to_array(JsonLines::read($this->stream("\n{\"a\":1}\n\n   \n{\"b\":2}\n")));

        $this->assertCount(2, $lines);
    }

    public function test_a_line_that_is_not_json_says_which_line(): void
    {
        $this->expectException(TransportFailed::class);
        $this->expectExceptionMessage('line 2 of the export is not JSON');

        iterator_to_array(JsonLines::read($this->stream("{\"a\":1}\n{not json\n")));
    }

    public function test_it_refuses_something_that_is_not_a_stream(): void
    {
        $this->expectException(InvalidArgument::class);

        /** @phpstan-ignore-next-line deliberately wrong, to prove the guard */
        iterator_to_array(JsonLines::read('a string'));
    }

    public function test_it_opens_a_file(): void
    {
        $path = $this->temporaryFile(self::DOCUMENTED_EXPORT);

        $this->assertCount(7, iterator_to_array(JsonLines::open($path)));

        unlink($path);
    }

    /**
     * A compressed export is never fully decompressed anywhere: the stream is
     * opened through compress.zlib:// and read as it goes.
     */
    public function test_it_opens_a_gzipped_file_without_unpacking_it_first(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'salsify-export-').'.jsonl.gz';
        file_put_contents($path, (string) gzencode(self::DOCUMENTED_EXPORT));

        $lines = iterator_to_array(JsonLines::open($path, compressed: true));

        $this->assertCount(7, $lines);
        $this->assertTrue($lines[6]->isRecord());

        unlink($path);
    }

    public function test_a_file_that_is_not_there_says_so(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('could not be opened for reading');

        iterator_to_array(JsonLines::open('/nonexistent/export.jsonl'));
    }

    /**
     * The claim this class exists to make, measured rather than asserted.
     *
     * Fifty thousand records - a small catalogue - written to a file, then
     * read twice: once by decoding the whole document the obvious way, and
     * once through the reader. The obvious way holds the file as a string and
     * again as an array; the reader holds one line.
     */
    public function test_reading_a_large_export_does_not_grow_with_the_file(): void
    {
        $path = $this->largeExport(50_000);
        $fileSize = (int) filesize($path);

        $this->assertGreaterThan(5_000_000, $fileSize, 'the fixture should be big enough to matter');

        gc_collect_cycles();
        $before = memory_get_usage();
        $counted = 0;

        foreach (JsonLines::records(JsonLines::open($path)) as $record) {
            $counted++;
            // Touch it, so nothing can be optimised away.
            $this->assertNotNull($record->property('SKU'));
        }

        $used = memory_get_usage() - $before;

        $this->assertSame(50_000, $counted);
        $this->assertLessThan(
            1_000_000,
            $used,
            sprintf('streaming a %d-byte export should not hold it in memory, and this used %d bytes', $fileSize, $used),
        );

        // And the comparison: the same file, read the obvious way.
        gc_collect_cycles();
        $before = memory_get_usage();
        $whole = (string) file_get_contents($path);
        $usedByReadingItAll = memory_get_usage() - $before;
        unset($whole);

        $this->assertGreaterThan(
            $used * 5,
            $usedByReadingItAll,
            'reading the file into a string should cost far more than streaming it',
        );

        unlink($path);
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            $this->fail('could not open a memory stream');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'salsify-export-');

        if ($path === false) {
            $this->fail('no writable temporary directory');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function largeExport(int $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'salsify-big-');

        if ($path === false) {
            $this->fail('no writable temporary directory');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->fail('could not write the fixture');
        }

        fwrite($handle, '{"salsify:entity_type":"header","version":"1","scope":"all"}'."\n");

        for ($i = 0; $i < $records; $i++) {
            fwrite($handle, (string) json_encode([
                'salsify:entity_type' => 'record',
                'SKU' => sprintf('SKU-%06d', $i),
                'Display Name' => 'A product with a reasonably long name, '.$i,
                'Description' => str_repeat('Lorem ipsum dolor sit amet. ', 3),
                'Brand' => 'Acme',
                'Type' => 'Television',
            ])."\n");
        }

        fclose($handle);

        return $path;
    }
}
