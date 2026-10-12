<?php

declare(strict_types=1);

namespace Piston\Salsify\Records;

use Piston\Salsify\Entity\Record;
use Piston\Salsify\Entity\RecordId;
use Piston\Salsify\Http\Client;
use Piston\Salsify\Http\Request;

/**
 * Records, one at a time.
 *
 * Which is the right tool for a handful and the wrong one for a catalogue.
 * Salsify says so itself - "we suggest using imports when the number of
 * entities to create or update is in the hundreds or more" - and the rate
 * limit says it louder: ten thousand requests an hour for the whole
 * organization means a loop over a catalogue of fifty thousand products cannot
 * finish, and spends every other integration's budget trying.
 *
 * So: this for the product a user just edited, Imports for the nightly feed,
 * Exports for the mirror. The methods below are deliberately not a paginated
 * `all()`, because there is no honest way to offer one that does not invite
 * the loop.
 */
final class RecordClient
{
    public function __construct(private readonly Client $client) {}

    /** One record, by the id the organization gives it. */
    public function get(RecordId|string $id): Record
    {
        $id = $id instanceof RecordId ? $id : RecordId::of($id);

        return Record::fromArray($this->client->send(Request::get($this->path($id)))->body);
    }

    /**
     * Create a record.
     *
     * The id lives in whichever property holds the ID role, so it is part of
     * the record rather than an argument here - see Record::of().
     */
    public function create(Record $record): Record
    {
        /** @var array<string, mixed> $properties */
        $properties = $record->toArray();

        return Record::fromArray($this->client->send(Request::post('records', $properties))->body);
    }

    /**
     * Update a record, mentioning only what changes.
     *
     * Salsify "will only modify the properties mentioned in the API request",
     * so sending a whole record back is both slower and riskier than sending
     * the two properties that moved: anything read a moment ago and written
     * back unchanged will still overwrite whatever happened in between.
     *
     * @param  array<string, mixed>  $properties
     */
    public function update(RecordId|string $id, array $properties): Record
    {
        $id = $id instanceof RecordId ? $id : RecordId::of($id);

        return Record::fromArray($this->client->send(Request::put($this->path($id), $properties))->body);
    }

    public function delete(RecordId|string $id): void
    {
        $id = $id instanceof RecordId ? $id : RecordId::of($id);

        $this->client->send(Request::delete($this->path($id)));
    }

    /**
     * The same operations against the legacy `products` path.
     *
     * Salsify's documentation describes both, and says an organization
     * without custom records gets identical behaviour from either. `records`
     * is the current name and the default here; this exists for an
     * organization whose contract or tooling still speaks of products.
     */
    public function products(): self
    {
        return new self($this->client);
    }

    private function path(RecordId $id): string
    {
        return 'records/'.rawurlencode($id->value());
    }
}
