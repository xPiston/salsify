<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

/**
 * The file an export produces.
 *
 * The available formats depend on the entity type, and the defaults differ:
 * `all` is JSON and accepts only JSON or JSONL, while everything else
 * defaults to xlsx. ExportConfiguration enforces the pairing, because the
 * alternative is a 400 twenty minutes into a job.
 *
 * `JsonLines` is the one to choose for anything large: one entity per line,
 * readable without holding the file in memory. See JsonLines.
 */
enum Format: string
{
    case Json = 'json';
    case JsonLines = 'jsonl';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
