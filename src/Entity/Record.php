<?php

declare(strict_types=1);

namespace Piston\Salsify\Entity;

use DateTimeImmutable;

/**
 * A record - which is also a product.
 *
 * Salsify's own documentation settles this: organizations without the custom
 * records feature use the same API and "it will behave the same as the
 * products API". Same system metadata, same serialization, same parent/child
 * inheritance. One class, therefore, rather than two that would drift.
 *
 * A record is **schemaless**: its properties are whatever the organization
 * defined, keyed by property id, and only the system ones are known in
 * advance. So this keeps the whole array exactly as it arrived and offers
 * typed accessors for the `salsify:` keys on top. Nothing is dropped, nothing
 * is renamed, and a property this library has never heard of reads back
 * identically - which for a PIM is the whole point: the interesting data is
 * precisely the part an SDK cannot know about.
 *
 * Localized values are nested one level, `locale => value`. `localized()`
 * reads them without the caller having to know whether a given property is
 * localized in this organization, which is configuration rather than code.
 */
final class Record
{
    /** @param array<string, mixed> $properties */
    private function __construct(private readonly array $properties) {}

    /** @param array<string, mixed> $properties */
    public static function fromArray(array $properties): self
    {
        return new self($properties);
    }

    /**
     * A record to create or update, from its properties.
     *
     * The id goes in under the property that holds the ID role in the
     * organization - which this library cannot know - so it is named by the
     * caller: `Record::of('SKU', $id, ['Name' => 'Big TV'])`.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function of(string $idProperty, RecordId|string $id, array $properties = []): self
    {
        $id = $id instanceof RecordId ? $id : RecordId::of($id);

        return new self([$idProperty => $id->value(), ...$properties]);
    }

    /**
     * Everything, exactly as it arrived.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->properties;
    }

    public function property(string $id): mixed
    {
        return $this->properties[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->properties);
    }

    /**
     * One locale of a localized property.
     *
     * Returns null rather than the whole map when the property is not
     * localized in this organization, because a caller asking for `en-US` has
     * said what shape it expects.
     */
    public function localized(string $id, string $locale): ?string
    {
        $value = $this->properties[$id] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $localized = $value[$locale] ?? null;

        return is_string($localized) || is_int($localized) ? (string) $localized : null;
    }

    /**
     * The locales a property carries, or an empty list if it carries none.
     *
     * @return list<string>
     */
    public function locales(string $id): array
    {
        $value = $this->properties[$id] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_keys($value), 'is_string'));
    }

    /**
     * The properties that are the organization's own, without the system ones.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return array_filter(
            $this->properties,
            static fn (string $key): bool => ! str_starts_with($key, 'salsify:'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    // --- System metadata, all prefixed `salsify:` on the wire.

    /** The id that follows the organization's ID property, and changes with it. */
    public function id(): ?string
    {
        return $this->systemString('salsify:id');
    }

    /** The permanent handle. This is the one to store. */
    public function systemId(): ?string
    {
        return $this->systemString('salsify:system_id');
    }

    /**
     * The parent, when the organization uses parent/child inheritance.
     *
     * Null means one of two different things - no relationship, or *being* a
     * parent - which is Salsify's design and worth knowing before treating it
     * as "standalone".
     */
    public function parentId(): ?string
    {
        return $this->systemString('salsify:parent_id');
    }

    public function isChild(): bool
    {
        return $this->parentId() !== null;
    }

    public function version(): ?int
    {
        $value = $this->properties['salsify:version'] ?? null;

        return is_int($value) ? $value : (is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null);
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->systemDate('salsify:created_at');
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->systemDate('salsify:updated_at');
    }

    public function profileAssetId(): ?string
    {
        return $this->systemString('salsify:profile_asset_id');
    }

    /** @return list<mixed> */
    public function digitalAssets(): array
    {
        $value = $this->properties['salsify:digital_assets'] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * The same record with properties added or replaced.
     *
     * For an update: Salsify only modifies the properties a request mentions,
     * so the right shape for "set the colour" is a record with one property in
     * it, not the whole thing sent back.
     *
     * @param  array<string, mixed>  $properties
     */
    public function with(array $properties): self
    {
        return new self([...$this->properties, ...$properties]);
    }

    private function systemString(string $key): ?string
    {
        $value = $this->properties[$key] ?? null;

        return (is_string($value) && $value !== '') || is_int($value) ? (string) $value : null;
    }

    private function systemDate(string $key): ?DateTimeImmutable
    {
        return Timestamp::parse($this->systemString($key));
    }
}
