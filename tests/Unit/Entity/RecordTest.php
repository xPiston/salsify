<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Entity\Record;
use Piston\Salsify\Entity\RecordId;
use Piston\Salsify\Exception\InvalidArgument;

final class RecordTest extends TestCase
{
    /** The example printed in Salsify's records documentation. */
    private const DOCUMENTED = [
        'salsify:id' => 'Record 1',
        'salsify:created_at' => '2021-06-21T18:28:02.935Z',
        'salsify:updated_at' => '2021-06-21T19:21:29.960Z',
        'salsify:version' => 2,
        'salsify:profile_asset_id' => null,
        'salsify:system_id' => 's-1a3d74ea-5831-44f4-a181-bdfe1516c368',
        'Record ID' => 'Record 1',
        'Taxonomy' => 'T-Shirts',
        'Description' => 'Soft, cool, comfortable.',
        'Localizable' => ['en-US' => 'English', 'pt-PT' => 'Português'],
    ];

    public function test_it_reads_the_documented_record(): void
    {
        $record = Record::fromArray(self::DOCUMENTED);

        $this->assertSame('Record 1', $record->id());
        $this->assertSame('s-1a3d74ea-5831-44f4-a181-bdfe1516c368', $record->systemId());
        $this->assertSame(2, $record->version());
        $this->assertSame('2021-06-21 18:28:02', $record->createdAt()?->format('Y-m-d H:i:s'));
        $this->assertSame('2021-06-21 19:21:29', $record->updatedAt()?->format('Y-m-d H:i:s'));
        $this->assertNull($record->profileAssetId());
        $this->assertNull($record->parentId());
        $this->assertFalse($record->isChild());
    }

    /**
     * The point of a PIM SDK: the interesting data is exactly the part the
     * SDK cannot know about, so nothing may be dropped or renamed.
     */
    public function test_nothing_is_lost(): void
    {
        $record = Record::fromArray(self::DOCUMENTED);

        $this->assertSame(self::DOCUMENTED, $record->toArray());
        $this->assertSame('T-Shirts', $record->property('Taxonomy'));
        $this->assertSame('Soft, cool, comfortable.', $record->property('Description'));
    }

    public function test_the_organizations_own_properties_can_be_had_without_the_system_ones(): void
    {
        $attributes = Record::fromArray(self::DOCUMENTED)->attributes();

        $this->assertSame(['Record ID', 'Taxonomy', 'Description', 'Localizable'], array_keys($attributes));
    }

    public function test_it_reads_a_localized_value(): void
    {
        $record = Record::fromArray(self::DOCUMENTED);

        $this->assertSame('English', $record->localized('Localizable', 'en-US'));
        $this->assertSame('Português', $record->localized('Localizable', 'pt-PT'));
        $this->assertNull($record->localized('Localizable', 'fr-FR'), 'a locale with no value');
        $this->assertNull($record->localized('Taxonomy', 'en-US'), 'a property that is not localized');
        $this->assertSame(['en-US', 'pt-PT'], $record->locales('Localizable'));
        $this->assertSame([], $record->locales('Taxonomy'));
    }

    /**
     * `salsify:id` follows whichever property holds the ID role, so it
     * changes when that property is edited. `salsify:system_id` never does.
     * Storing the wrong one is the bug this distinction exists to prevent.
     */
    public function test_the_two_identifiers_are_not_the_same_thing(): void
    {
        $record = Record::fromArray(self::DOCUMENTED);

        $this->assertNotSame($record->id(), $record->systemId());
        $this->assertStringStartsWith('s-', (string) $record->systemId());
    }

    public function test_a_child_record_names_its_parent(): void
    {
        $record = Record::fromArray(['salsify:id' => 'variant-1', 'salsify:parent_id' => 'parent-1']);

        $this->assertTrue($record->isChild());
        $this->assertSame('parent-1', $record->parentId());
    }

    public function test_a_record_to_create_carries_its_id_under_the_organizations_own_property(): void
    {
        $record = Record::of('SKU', '3635065', ['Display Name' => '40 in. TV']);

        $this->assertSame(['SKU' => '3635065', 'Display Name' => '40 in. TV'], $record->toArray());
    }

    public function test_it_is_immutable(): void
    {
        $record = Record::fromArray(['SKU' => '1']);
        $updated = $record->with(['Colour' => 'Purple']);

        $this->assertSame(['SKU' => '1'], $record->toArray());
        $this->assertSame(['SKU' => '1', 'Colour' => 'Purple'], $updated->toArray());
    }

    public function test_a_missing_property_reads_as_null_rather_than_breaking(): void
    {
        $record = Record::fromArray([]);

        $this->assertNull($record->property('anything'));
        $this->assertNull($record->id());
        $this->assertNull($record->version());
        $this->assertNull($record->createdAt());
        $this->assertSame([], $record->digitalAssets());
        $this->assertFalse($record->has('anything'));
    }

    /**
     * `new DateTimeImmutable($value)` would accept all of these and return a
     * plausible date for each - which for a field an application stores and
     * compares is worse than returning nothing.
     */
    #[DataProvider('unreadableDates')]
    public function test_a_date_that_is_not_salsifys_format_is_null(string $value): void
    {
        $this->assertNull(Record::fromArray(['salsify:created_at' => $value])->createdAt());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableDates(): iterable
    {
        yield 'a relative expression PHP would happily parse' => ['yesterday'];
        yield 'now' => ['now'];
        yield 'an offset' => ['+1 week'];
        yield 'a date only' => ['2021-06-21'];
        yield 'nonsense' => ['not a date'];
        yield 'empty' => [''];
    }

    public function test_the_documented_date_formats_are_read(): void
    {
        foreach ([
            '2021-06-21T18:28:02.935Z',
            '2021-06-21T18:28:02Z',
            '2021-06-21T18:28:02+02:00',
        ] as $value) {
            $this->assertNotNull(
                Record::fromArray(['salsify:created_at' => $value])->createdAt(),
                $value.' should be readable',
            );
        }
    }

    #[DataProvider('refusedIds')]
    public function test_it_refuses_the_ids_salsify_refuses(string $id, string $because): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage($because);

        RecordId::of($id);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedIds(): iterable
    {
        yield 'empty' => ['', 'must not be empty'];
        yield 'a leading equals, which is a formula in a spreadsheet export' => ['=1+1', 'must not start with "="'];
        yield 'a leading underscore' => ['_internal', 'must not start with "_"'];
        yield 'the reserved prefix' => ['salsify-thing', 'must not start with "salsify"'];
        yield 'the reserved prefix in any case' => ['SALSIFY42', 'must not start with "salsify"'];
    }

    public function test_an_ordinary_id_is_accepted(): void
    {
        $this->assertSame('3635065', RecordId::of('3635065')->value());
        $this->assertSame('CMD-2026/0042', RecordId::of('CMD-2026/0042')->value(), 'unlike a payment reference, this one takes anything');
        $this->assertTrue(RecordId::of('a')->equals(RecordId::of('a')));
        $this->assertFalse(RecordId::of('a')->equals(RecordId::of('b')));
    }
}
