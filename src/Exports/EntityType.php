<?php

declare(strict_types=1);

namespace Piston\Salsify\Exports;

/**
 * What an export contains.
 *
 * `All` is the only one that produces a JSON or JSONL file carrying the whole
 * data model - properties, enumerated values, digital assets and records
 * together - and it is the one an integration that mirrors Salsify wants. The
 * others are spreadsheets by default.
 */
enum EntityType: string
{
    case All = 'all';
    case Product = 'product';
    case DigitalAsset = 'digital_asset';
    case Attribute = 'attribute';
    case AttributeValue = 'attribute_value';
}
