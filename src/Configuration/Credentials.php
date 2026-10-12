<?php

declare(strict_types=1);

namespace Piston\Salsify\Configuration;

use Piston\Salsify\Exception\InvalidArgument;
use SensitiveParameter;

/**
 * An API token and the organization it acts in.
 *
 * One object because the two belong together: the same user can be a member of
 * several organizations, and Salsify counts the rate limit **per
 * organization** - so the same token has a separate budget in each. A token
 * without the organization it is being used in is half a credential.
 */
final class Credentials
{
    private readonly string $token;

    private function __construct(#[SensitiveParameter] string $token, public readonly Organization $organization)
    {
        $this->token = $token;
    }

    public static function of(#[SensitiveParameter] string $token, Organization|string $organization): self
    {
        $token = trim($token);

        if ($token === '') {
            throw InvalidArgument::token('must not be empty');
        }

        if (str_starts_with(strtolower($token), 'bearer ')) {
            // A token pasted with the header prefix still looks right in a
            // config file and fails every request with a 401.
            throw InvalidArgument::token('must be the token alone, without the "Bearer " prefix the header adds');
        }

        return new self(
            $token,
            $organization instanceof Organization ? $organization : Organization::of($organization),
        );
    }

    /** The value of the Authorization header. */
    public function authorizationHeader(): string
    {
        return 'Bearer '.$this->token;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '*** not shown ***', 'organization' => $this->organization->id()];
    }
}
