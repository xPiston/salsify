<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Configuration\Organization;
use Piston\Salsify\Exception\InvalidArgument;

final class ConfigurationTest extends TestCase
{
    #[DataProvider('organizationIds')]
    public function test_it_accepts_the_shapes_salsify_shows(string $id): void
    {
        $this->assertSame($id, Organization::of($id)->id());
    }

    /**
     * Salsify's own examples are not the same shape as each other, so this is
     * loose on purpose: insisting on one of them would reject real
     * organizations to enforce a pattern nobody documented.
     *
     * @return iterable<string, array{string}>
     */
    public static function organizationIds(): iterable
    {
        yield 'four groups' => ['s-999-999-999-999'];
        yield 'a longer first group' => ['s-9c9999-9999-9999-9999'];
        yield 'a uuid' => ['s-1a3d74ea-5831-44f4-a181-bdfe1516c368'];
    }

    #[DataProvider('notOrganizationIds')]
    public function test_it_refuses_what_is_not_an_organization_id(string $id): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('after /orgs/');

        Organization::of($id);
    }

    /** @return iterable<string, array{string}> */
    public static function notOrganizationIds(): iterable
    {
        yield 'empty' => [''];
        yield 'without the prefix' => ['999-999-999-999'];
        yield 'a name' => ['my-organization'];
        yield 'a whole url' => ['https://app.salsify.com/orgs/s-999-999-999-999'];
    }

    public function test_credentials_hide_the_token(): void
    {
        $credentials = Credentials::of('a-secret-token', 's-1234-5678-9012-3456');

        $this->assertSame('Bearer a-secret-token', $credentials->authorizationHeader());

        $dumped = print_r($credentials, true);

        $this->assertStringNotContainsString('a-secret-token', $dumped);
        $this->assertStringContainsString('not shown', $dumped);
        $this->assertStringContainsString('s-1234-5678-9012-3456', $dumped, 'the organization is not a secret');
    }

    /**
     * A token pasted with the prefix the header adds still looks right in a
     * config file and fails every request with a 401.
     */
    public function test_a_token_pasted_with_the_bearer_prefix_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('without the "Bearer " prefix');

        Credentials::of('Bearer a-token', 's-1234-5678-9012-3456');
    }

    public function test_an_empty_token_is_refused_without_quoting_it(): void
    {
        try {
            Credentials::of('   ', 's-1234-5678-9012-3456');
            $this->fail('an empty token should have been refused');
        } catch (InvalidArgument $e) {
            $this->assertStringContainsString('must not be empty', $e->getMessage());
        }
    }

    /** The inconsistency is Salsify's; this is the one place that knows it. */
    public function test_the_two_base_paths(): void
    {
        $endpoint = new Endpoint(Organization::of('s-1234-5678-9012-3456'));

        $this->assertSame(
            'https://app.salsify.com/api/v1/orgs/s-1234-5678-9012-3456/records/42',
            $endpoint->versioned('records/42'),
        );

        $this->assertSame(
            'https://app.salsify.com/api/orgs/s-1234-5678-9012-3456/export_runs',
            $endpoint->unversioned('export_runs'),
        );
    }

    public function test_a_leading_slash_on_the_path_makes_no_difference(): void
    {
        $endpoint = new Endpoint(Organization::of('s-1234-5678-9012-3456'));

        $this->assertSame($endpoint->versioned('records/42'), $endpoint->versioned('/records/42'));
    }

    public function test_the_host_can_be_changed_and_its_trailing_slash_does_not_double(): void
    {
        $endpoint = new Endpoint(Organization::of('s-1234-5678-9012-3456'), 'https://salsify.internal/');

        $this->assertSame('https://salsify.internal/api/v1/orgs/s-1234-5678-9012-3456/records/42', $endpoint->versioned('records/42'));
    }
}
