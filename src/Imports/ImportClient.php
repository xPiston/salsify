<?php

declare(strict_types=1);

namespace Piston\Salsify\Imports;

use Piston\Salsify\Http\Client;
use Piston\Salsify\Http\Request;

/**
 * Imports: the way to change more than a handful of records.
 *
 * Salsify's own guidance, and the arithmetic of the rate limit, both point
 * here for anything in the hundreds. One import of fifty thousand products is
 * a few requests; the same work through the record endpoints is fifty thousand
 * of the organization's ten thousand hourly.
 *
 * The flow has several steps on Salsify's side - a mount point, a file
 * uploaded to it, an import configured to read it, and a run - and the shape
 * of each is documented more thinly than the export side. What is here is the
 * part that is unambiguous: starting an import that already exists, and asking
 * how it went. Creating mount points and uploading files is deliberately
 * absent rather than guessed at; see the README.
 */
final class ImportClient
{
    public function __construct(private readonly Client $client) {}

    /** Run an import that is already configured in Salsify. */
    public function start(string $importId): ImportRun
    {
        $reply = $this->client->send(
            Request::post('imports/'.rawurlencode($importId).'/runs')->unversioned(),
        );

        return ImportRun::fromArray($reply->body);
    }

    public function status(string $importId, string $runId): ImportRun
    {
        $reply = $this->client->send(
            Request::get(sprintf('imports/%s/runs/%s', rawurlencode($importId), rawurlencode($runId)))->unversioned(),
        );

        return ImportRun::fromArray($reply->body);
    }
}
