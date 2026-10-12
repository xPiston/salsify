<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

use Generator;
use Piston\Salsify\Exception\InvalidArgument;
use Piston\Salsify\Http\Client;
use Piston\Salsify\Http\Request;

/**
 * Exports: ask for a file, wait for it, read it.
 *
 * The three steps are deliberately three calls rather than one convenience
 * method that blocks. A full export takes minutes to hours; a method that
 * polled until done would hold a web request open, or a queue worker, and
 * would hide the one decision that matters - how often to ask, which is a
 * question about the organization's ten thousand requests an hour and not one
 * an SDK can answer.
 *
 * Note the path: export runs live under Salsify's **unversioned** prefix,
 * `/api/orgs/...`, while records live under `/api/v1/orgs/...`. That is not a
 * typo here; it is what their own OpenAPI definition declares.
 */
final class ExportClient
{
    public function __construct(private readonly Client $client) {}

    /** Start generating a file. Returns immediately, with an id. */
    public function start(ExportConfiguration $configuration): ExportRun
    {
        /** @var array<string, mixed> $body */
        $body = $configuration->toRequestBody();

        $reply = $this->client->send(Request::post('export_runs', $body)->unversioned());

        return ExportRun::fromArray($reply->body);
    }

    /**
     * Where that run has got to.
     *
     * The path is the conventional REST one, `export_runs/{id}`; Salsify
     * documents the start endpoint in full and describes this one only in
     * prose - "you include the export ID in subsequent calls". It is noted
     * here rather than hidden, because it is the one thing in this class that
     * could not be checked against a published definition.
     */
    public function status(ExportRun|string $run): ExportRun
    {
        $id = $run instanceof ExportRun ? $run->id() : $run;

        if ($id === null || $id === '') {
            throw InvalidArgument::because('export run', 'has no id to ask about', (string) $id);
        }

        $reply = $this->client->send(Request::get('export_runs/'.rawurlencode($id))->unversioned());

        return ExportRun::fromArray($reply->body);
    }

    /**
     * Read a finished export, line by line, without holding it in memory.
     *
     * Only for the JSON Lines format: the others are a spreadsheet or one
     * enormous JSON document, and neither streams.
     *
     * @return Generator<int, Line>
     */
    public function stream(ExportRun $run, bool $compressed = false): Generator
    {
        $url = $run->url();

        if ($url === null) {
            throw InvalidArgument::because(
                'export run',
                'has no file yet - its url is empty until the run completes',
                (string) $run->status(),
            );
        }

        yield from JsonLines::open($url, $compressed);
    }
}
