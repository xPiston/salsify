<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

use Generator;
use JsonException;
use Piston\Salsify\Exception\InvalidArgument;
use Piston\Salsify\Exception\TransportFailed;

/**
 * Reads a JSON Lines export without holding it in memory.
 *
 * This is the class the whole export side exists for. A full export of a real
 * PIM is hundreds of megabytes - Salsify's own import limits put a JSONL file
 * at up to 100 MB and a CSV at ten million rows - and the obvious code,
 * `json_decode(file_get_contents($url))`, needs the whole document resident
 * twice over: once as a string and once as an array. On a worker with a 256 MB
 * limit it does not finish, and the failure arrives twenty minutes into a job.
 *
 * Salsify evidently knows: the one PHP package they publish is a *streaming*
 * JSON parser. This is the same idea with less ceremony, because JSON Lines is
 * already one document per line - so a generator over the lines is the whole
 * implementation, and memory stays flat whatever the file weighs.
 *
 * Gzip is handled by opening the stream through `compress.zlib://`, which
 * means a compressed export never lands on disk uncompressed either.
 */
final class JsonLines
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Every line of an export, in order, one at a time.
     *
     * @param  resource  $stream
     * @return Generator<int, Line>
     */
    public static function read($stream): Generator
    {
        if (! is_resource($stream)) {
            throw InvalidArgument::because('stream', 'expected an open stream resource', get_debug_type($stream));
        }

        $number = 0;

        while (($line = fgets($stream)) !== false) {
            $number++;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw TransportFailed::notJson(0, sprintf('line %d of the export is not JSON: %s', $number, $e->getMessage()));
            }

            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            yield new Line($decoded);
        }
    }

    /**
     * The same, from a file or a URL - compressed or not.
     *
     * `compress.zlib://` reads a gzip file as it goes, so a 100 MB export
     * compressed to 8 MB is never fully decompressed anywhere.
     *
     * @return Generator<int, Line>
     */
    public static function open(string $path, bool $compressed = false): Generator
    {
        $target = $compressed ? 'compress.zlib://'.$path : $path;
        $stream = @fopen($target, 'rb');

        if ($stream === false) {
            throw InvalidArgument::because('export file', 'could not be opened for reading', $path);
        }

        try {
            yield from self::read($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Only the records, already turned into Record objects.
     *
     * The shape most integrations want: the properties and assets that come
     * first in the file describe the schema, and a job that only mirrors
     * products does not need them.
     *
     * @param  Generator<int, Line>|iterable<Line>  $lines
     * @return Generator<int, \Piston\Salsify\Entity\Record>
     */
    public static function records(iterable $lines): Generator
    {
        foreach ($lines as $line) {
            if ($line->isRecord()) {
                yield $line->toRecord();
            }
        }
    }
}
