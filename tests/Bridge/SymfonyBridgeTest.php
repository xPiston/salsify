<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Piston\Salsify\Bridge\Symfony\SalsifyBundle;
use Piston\Salsify\Bridge\Symfony\WebhookRequest;
use Piston\Salsify\Salsify;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * The bundle, compiled by Symfony's own container - which is the only way to
 * find a service definition that cannot be built, as two previous packages in
 * this series learned the hard way.
 */
final class SymfonyBridgeTest extends TestCase
{
    public function test_it_builds_a_configured_client(): void
    {
        $salsify = $this->salsify();

        $this->assertSame('s-1234-5678-9012-3456', $salsify->credentials->organization->id());
    }

    public function test_the_same_instance_is_shared(): void
    {
        $container = $this->container();

        $this->assertSame($container->get(Salsify::class), $container->get(Salsify::class));
    }

    public function test_the_verifier_is_a_service_when_a_webhook_url_is_configured(): void
    {
        $container = $this->container();

        $this->assertInstanceOf(SignatureVerifier::class, $container->get(SignatureVerifier::class));
    }

    /**
     * And not a service when there is no URL, because a verifier without the
     * URL it is meant to check would verify everything against the wrong
     * string - a failure that looks like "Salsify's signatures are broken".
     */
    public function test_there_is_no_verifier_without_a_webhook_url(): void
    {
        $container = $this->container(['webhook_url' => null]);

        $this->assertFalse($container->has(SignatureVerifier::class));
    }

    public function test_the_token_and_the_organization_are_required(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['token' => null]);
    }

    public function test_a_tolerance_below_one_second_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['webhook_tolerance' => 0]);
    }

    public function test_an_organization_that_is_not_one_is_refused_when_the_service_is_built(): void
    {
        $this->expectException(\Piston\Salsify\Exception\InvalidArgument::class);

        $this->container(['organization' => 'not-an-org'])->get(Salsify::class);
    }

    public function test_the_extension_is_called_salsify(): void
    {
        $this->assertSame('salsify', (new SalsifyBundle)->getContainerExtension()?->getAlias());
    }

    public function test_the_webhook_helper_reads_the_raw_body(): void
    {
        $body = '{"salsify:entity_type":"record"}';

        $request = Request::create('/webhooks/salsify', 'POST', [], [], [], [
            'HTTP_X_SALSIFY_TIMESTAMP' => '1760000000',
            'HTTP_X_SALSIFY_REQUEST_ID' => 'req-1',
            'HTTP_X_SALSIFY_ORGANIZATION_ID' => 's-1234-5678-9012-3456',
            'HTTP_X_SALSIFY_SIGNATURE_V1' => base64_encode('signature'),
            'HTTP_X_SALSIFY_CERT_URL' => 'https://webhooks-auth.salsify.com/certs/a.pem',
        ], $body);

        $this->assertSame($body, WebhookRequest::from($request)->body);
    }

    /** @param array<string, mixed> $overrides */
    private function salsify(array $overrides = []): Salsify
    {
        $service = $this->container($overrides)->get(Salsify::class);

        $this->assertInstanceOf(Salsify::class, $service);

        return $service;
    }

    /** @param array<string, mixed> $overrides */
    private function container(array $overrides = []): ContainerBuilder
    {
        $config = [
            'token' => 'a-token',
            'organization' => 's-1234-5678-9012-3456',
            'webhook_url' => 'https://shop.test/webhooks/salsify',
            ...$overrides,
        ];

        foreach ($config as $name => $value) {
            if ($value === null) {
                unset($config[$name]);
            }
        }

        $builder = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.project_dir' => dirname(__DIR__, 2),
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
        ]));

        $extension = (new SalsifyBundle)->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load([$config], $builder);

        foreach ([Salsify::class, SignatureVerifier::class] as $service) {
            if ($builder->hasDefinition($service)) {
                $builder->getDefinition($service)->setPublic(true);
            }
        }

        $builder->compile();

        return $builder;
    }
}
