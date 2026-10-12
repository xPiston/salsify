<?php

declare(strict_types=1);

namespace Piston\Salsify;

use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Configuration\Organization;
use Piston\Salsify\Exception\MissingHttpClient;
use Piston\Salsify\Exports\ExportClient;
use Piston\Salsify\Http\Client;
use Piston\Salsify\Http\RateLimit;
use Piston\Salsify\Imports\ImportClient;
use Piston\Salsify\Records\RecordClient;
use Piston\Salsify\Webhooks\CachedCertificates;
use Piston\Salsify\Webhooks\HttpCertificateFetcher;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * Salsify, in one object.
 *
 * Four doors - records, exports, imports, webhooks - and they are four because
 * the platform is four, not because the API is. Which one to use for a given
 * job is the first decision an integration makes and the one it most often
 * gets wrong: the rate limit is ten thousand requests an hour **for the whole
 * organization**, so a loop over records is a tool for tens of things and
 * imports and exports are the tools for the rest.
 *
 * Nothing holds state between calls except the last seen rate limit, so one
 * instance per organization, built once, is the intended shape.
 */
final class Salsify
{
    private readonly Client $client;

    public function __construct(
        public readonly Credentials $credentials,
        ?Endpoint $endpoint = null,
        private readonly ?ClientInterface $http = null,
        private readonly ?RequestFactoryInterface $requests = null,
        private readonly ?StreamFactoryInterface $streams = null,
    ) {
        $endpoint ??= new Endpoint($credentials->organization);

        $transport = $http ?? Discovery::client();
        $requestFactory = $requests ?? Discovery::requestFactory();
        $streamFactory = $streams ?? Discovery::streamFactory();

        if ($transport === null || $requestFactory === null || $streamFactory === null) {
            throw MissingHttpClient::create();
        }

        $this->client = new Client($credentials, $endpoint, $transport, $requestFactory, $streamFactory);
    }

    public static function with(
        #[SensitiveParameter] string $token,
        Organization|string $organization,
    ): self {
        return new self(Credentials::of($token, $organization));
    }

    public function records(): RecordClient
    {
        return new RecordClient($this->client);
    }

    public function exports(): ExportClient
    {
        return new ExportClient($this->client);
    }

    public function imports(): ImportClient
    {
        return new ImportClient($this->client);
    }

    /**
     * A verifier for the webhooks this application receives.
     *
     * The endpoint URL is required and is the one **Salsify was configured to
     * call**, because that is what it signs - see SignatureVerifier. The
     * certificates it downloads are remembered for the life of the process.
     */
    public function webhooks(string $endpointUrl, ?int $toleranceInSeconds = null): SignatureVerifier
    {
        $transport = $this->http ?? Discovery::client();
        $requests = $this->requests ?? Discovery::requestFactory();

        if ($transport === null || $requests === null) {
            throw MissingHttpClient::create();
        }

        return new SignatureVerifier(
            $endpointUrl,
            new CachedCertificates(new HttpCertificateFetcher($transport, $requests)),
            $toleranceInSeconds ?? SignatureVerifier::DEFAULT_TOLERANCE,
        );
    }

    /**
     * What the last response said about the organization's budget.
     *
     * Read it in a loop. `isRunningOut()` is the signal to slow down, and it
     * arrives before the 429 rather than after.
     */
    public function rateLimit(): RateLimit
    {
        return $this->client->rateLimit();
    }

    /** For a call this SDK has no method for yet. */
    public function http(): Client
    {
        return $this->client;
    }
}
