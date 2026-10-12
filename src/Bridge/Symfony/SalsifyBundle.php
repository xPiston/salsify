<?php

declare(strict_types=1);

namespace Piston\Salsify\Bridge\Symfony;

use Piston\Salsify\Configuration\Credentials;
use Piston\Salsify\Configuration\Endpoint;
use Piston\Salsify\Salsify;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Wires Salsify into a Symfony application.
 *
 * ```yaml
 * # config/packages/salsify.yaml
 * salsify:
 *     token: '%env(SALSIFY_TOKEN)%'
 *     organization: '%env(SALSIFY_ORGANIZATION)%'
 *     webhook_url: '%env(SALSIFY_WEBHOOK_URL)%'
 * ```
 */
final class SalsifyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'salsify';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('token')->isRequired()->cannotBeEmpty()
            ->info('The API token from your Salsify user profile, without the "Bearer " prefix.')->end()
            ->scalarNode('organization')->isRequired()->cannotBeEmpty()
            ->info('The organization id from the app URL after /orgs/. The rate limit is counted per organization.')->end()
            ->scalarNode('host')->defaultValue(Endpoint::DEFAULT_HOST)
            ->info('Only worth changing for a Salsify instance that is not the public one.')->end()
            ->scalarNode('webhook_url')->defaultNull()
            ->info('The URL you gave Salsify for webhooks, exactly: it is part of what the signature covers.')->end()
            ->integerNode('webhook_tolerance')->defaultValue(SignatureVerifier::DEFAULT_TOLERANCE)->min(1)
            ->info('How old a webhook may be, in seconds. Salsify recommends 300; raising it widens the replay window.')->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{token: string, organization: string, host: string, webhook_url: ?string, webhook_tolerance: int} $config */
        $services = $container->services();

        $services->set(Credentials::class)
            ->factory([Credentials::class, 'of'])
            ->args([$config['token'], $config['organization']]);

        $services->set(Endpoint::class)
            ->args([
                inline_service(\Piston\Salsify\Configuration\Organization::class)
                    ->factory([\Piston\Salsify\Configuration\Organization::class, 'of'])
                    ->args([$config['organization']]),
                $config['host'],
            ]);

        $services->set(Salsify::class)
            ->args([
                service(Credentials::class),
                service(Endpoint::class),
                service(ClientInterface::class)->nullOnInvalid(),
                service(RequestFactoryInterface::class)->nullOnInvalid(),
                service(StreamFactoryInterface::class)->nullOnInvalid(),
            ]);

        if (($config['webhook_url'] ?? null) !== null && $config['webhook_url'] !== '') {
            $services->set(SignatureVerifier::class)
                ->factory([service(Salsify::class), 'webhooks'])
                ->args([$config['webhook_url'], $config['webhook_tolerance']]);
        }
    }
}
