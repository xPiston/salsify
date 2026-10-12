<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

use Piston\Salsify\Exception\InvalidArgument;

/**
 * What to export, as the `configuration` object of a start request.
 *
 * Immutable and chainable. Every option is one Salsify documents, and the two
 * rules it states are enforced here rather than discovered from a 400:
 * `all` accepts only JSON and JSONL, and the spreadsheet formats are not
 * available for it.
 */
final class ExportConfiguration
{
    /** @param array<string, scalar> $options */
    private function __construct(private readonly array $options) {}

    public static function of(EntityType $entityType, ?Format $format = null): self
    {
        $format ??= $entityType === EntityType::All ? Format::Json : Format::Xlsx;

        if ($entityType === EntityType::All && ! in_array($format, [Format::Json, Format::JsonLines], true)) {
            throw InvalidArgument::because(
                'format',
                'an export of every entity type can only be json or jsonl',
                $format->value,
            );
        }

        if ($entityType !== EntityType::All && $entityType !== EntityType::Product && $format === Format::Json) {
            throw InvalidArgument::because(
                'format',
                sprintf('%s exports are csv or xlsx', $entityType->value),
                $format->value,
            );
        }

        return new self(['entity_type' => $entityType->value, 'format' => $format->value]);
    }

    /** Everything, as one streamable JSONL file. The shape to mirror an org. */
    public static function everything(bool $compressed = true): self
    {
        return self::of(EntityType::All, Format::JsonLines)->compressed($compressed);
    }

    public function compressed(bool $compress = true): self
    {
        return $this->set('compress', $compress);
    }

    /**
     * Only these properties, by their external ids.
     *
     * Salsify wants them as one string of single-quoted, comma-separated
     * values - `"'Property ID 1','Property ID 2'"` - which is a format nobody
     * guesses right the first time, so it is built from a list here.
     *
     * @param  list<string>  $propertyIds
     */
    public function onlyProperties(array $propertyIds): self
    {
        if ($propertyIds === []) {
            throw InvalidArgument::because('properties', 'name at least one property, or do not restrict them', '[]');
        }

        foreach ($propertyIds as $id) {
            if (str_contains($id, "'")) {
                throw InvalidArgument::because('properties', 'a property id cannot contain a single quote, which separates them', $id);
            }
        }

        return $this
            ->set('include_all_columns', false)
            ->set('properties', "'".implode("','", $propertyIds)."'");
    }

    /** A Salsify filter expression, in their filtering language. */
    public function filtered(string $filter): self
    {
        return $this->set('filter', $filter);
    }

    /** Products only: every product, rather than the leaves of the hierarchy. */
    public function includingParents(): self
    {
        return $this->set('product_type', 'all');
    }

    /** For an option this library does not have a method for yet. */
    public function with(string $option, string|int|float|bool $value): self
    {
        return $this->set($option, $value);
    }

    /** @return array<string, scalar> */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * The body of a start request.
     *
     * @return array{configuration: array<string, scalar>}
     */
    public function toRequestBody(): array
    {
        return ['configuration' => $this->options];
    }

    public function entityType(): EntityType
    {
        return EntityType::from((string) $this->options['entity_type']);
    }

    public function format(): Format
    {
        return Format::from((string) $this->options['format']);
    }

    public function isCompressed(): bool
    {
        return (bool) ($this->options['compress'] ?? false);
    }

    private function set(string $option, string|int|float|bool $value): self
    {
        return new self([...$this->options, $option => $value]);
    }
}
