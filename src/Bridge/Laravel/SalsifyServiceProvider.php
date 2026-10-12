<?php

declare(strict_types=1);

namespace Piston\Salsify\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Configuration\Organization;
use Piston\Salsify\Salsify;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Wires Salsify into a Laravel application.
 *
 * The signature verifier is a binding of its own, because the endpoint that
 * receives webhooks usually needs nothing else - and asking for the whole SDK
 * to check a signature would drag an HTTP client into a request that should
 * be doing as little as possible before answering.
 */
final class SalsifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/salsify.php', 'salsify');

        $this->app->singleton(Salsify::class, function (Container $app): Salsify {
            $config = $this->config($app);

            $credentials = Credentials::of(
                self::required($config, 'token', 'SALSIFY_TOKEN'),
                Organization::of(self::required($config, 'organization', 'SALSIFY_ORGANIZATION')),
            );

            $host = $config['host'] ?? null;

            return new Salsify(
                credentials: $credentials,
                endpoint: new Endpoint(
                    $credentials->organization,
                    is_string($host) && $host !== '' ? $host : Endpoint::DEFAULT_HOST,
                ),
                http: self::optional($app, ClientInterface::class),
                requests: self::optional($app, RequestFactoryInterface::class),
                streams: self::optional($app, StreamFactoryInterface::class),
            );
        });

        $this->app->singleton(SignatureVerifier::class, function (Container $app): SignatureVerifier {
            $config = $this->config($app);
            $tolerance = $config['webhook_tolerance'] ?? SignatureVerifier::DEFAULT_TOLERANCE;

            return $app->make(Salsify::class)->webhooks(
                self::required($config, 'webhook_url', 'SALSIFY_WEBHOOK_URL'),
                is_numeric($tolerance) ? (int) $tolerance : SignatureVerifier::DEFAULT_TOLERANCE,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/config/salsify.php' => $this->app->configPath('salsify.php'),
            ], 'salsify-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Salsify::class, SignatureVerifier::class];
    }

    /** @return array<string, mixed> */
    private function config(Container $app): array
    {
        $config = $app->make(Repository::class)->get('salsify', []);

        /** @var array<string, mixed> $config */
        return is_array($config) ? $config : [];
    }

    /** @param array<string, mixed> $config */
    private static function required(array $config, string $key, string $variable): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf(
                'Salsify is not configured: %s is missing. Publish the config with '
                .'`php artisan vendor:publish --tag=salsify-config` to see every option.',
                $variable,
            ));
        }

        return $value;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private static function optional(Container $app, string $abstract): ?object
    {
        return $app->bound($abstract) ? $app->make($abstract) : null;
    }
}
