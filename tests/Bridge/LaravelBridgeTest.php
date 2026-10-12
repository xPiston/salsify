<?php

declare(strict_types=1);

namespace Piston\Salsify\Tests\Bridge;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use Piston\Salsify\Bridge\Laravel\SalsifyServiceProvider;
use Piston\Salsify\Bridge\Laravel\WebhookRequest;
use Piston\Salsify\Salsify;
use Piston\Salsify\Webhooks\SignatureVerifier;
use Piston\Salsify\Webhooks\SignedRequest;
use RuntimeException;

final class LaravelBridgeTest extends TestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [SalsifyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Repository::class)->set([
            'salsify.token' => 'a-token',
            'salsify.organization' => 's-1234-5678-9012-3456',
            'salsify.webhook_url' => 'https://shop.test/webhooks/salsify',
        ]);
    }

    public function test_it_resolves_a_configured_client(): void
    {
        $salsify = $this->laravel()->make(Salsify::class);

        $this->assertSame('s-1234-5678-9012-3456', $salsify->credentials->organization->id());
        $this->assertSame($salsify, $this->laravel()->make(Salsify::class), 'it is a singleton');
    }

    public function test_the_verifier_resolves_on_its_own(): void
    {
        // A webhook endpoint needs nothing else, and should not have to build
        // the whole SDK to check a signature.
        $this->assertInstanceOf(SignatureVerifier::class, $this->laravel()->make(SignatureVerifier::class));
    }

    public function test_the_default_config_is_merged(): void
    {
        $this->assertSame('https://app.salsify.com', config('salsify.host'));
        $this->assertSame(300, config('salsify.webhook_tolerance'));
    }

    public function test_the_config_file_can_be_published(): void
    {
        $published = $this->laravel()->configPath('salsify.php');
        @unlink($published);

        $command = $this->artisan('vendor:publish', ['--tag' => 'salsify-config']);

        $this->assertInstanceOf(PendingCommand::class, $command);
        $this->assertSame(0, $command->run());

        $this->assertFileExists($published);
        $this->assertStringContainsString('SALSIFY_TOKEN', (string) file_get_contents($published));

        @unlink($published);
    }

    public function test_a_missing_token_says_which_variable_to_set(): void
    {
        config(['salsify.token' => null]);
        $this->laravel()->forgetInstance(Salsify::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SALSIFY_TOKEN is missing');

        $this->laravel()->make(Salsify::class);
    }

    public function test_a_missing_organization_says_so_too(): void
    {
        config(['salsify.organization' => '']);
        $this->laravel()->forgetInstance(Salsify::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SALSIFY_ORGANIZATION is missing');

        $this->laravel()->make(Salsify::class);
    }

    /**
     * The signature covers the raw body. A helper that handed over
     * `$request->all()` would verify a re-encoded document and fail on the
     * first payload whose key order differs from PHP's.
     */
    public function test_the_webhook_helper_reads_the_raw_body_and_the_headers(): void
    {
        $body = '{"salsify:entity_type":"record","salsify:id":"3635065"}';

        $request = Request::create('/webhooks/salsify', 'POST', [], [], [], [
            'HTTP_X_SALSIFY_TIMESTAMP' => '1760000000',
            'HTTP_X_SALSIFY_REQUEST_ID' => 'req-1',
            'HTTP_X_SALSIFY_ORGANIZATION_ID' => 's-1234-5678-9012-3456',
            'HTTP_X_SALSIFY_SIGNATURE_V1' => base64_encode('signature'),
            'HTTP_X_SALSIFY_CERT_URL' => 'https://webhooks-auth.salsify.com/certs/a.pem',
        ], $body);

        $signed = WebhookRequest::from($request);

        $this->assertInstanceOf(SignedRequest::class, $signed);
        $this->assertSame($body, $signed->body);
        $this->assertSame('1760000000', $signed->timestamp);
        $this->assertSame('req-1', $signed->requestId);
        $this->assertSame('s-1234-5678-9012-3456', $signed->organizationId);
        $this->assertSame('https://webhooks-auth.salsify.com/certs/a.pem', $signed->certificateUrl);
    }

    public function test_the_provider_declares_what_it_provides(): void
    {
        $provider = new SalsifyServiceProvider($this->laravel());

        $this->assertSame([Salsify::class, SignatureVerifier::class], $provider->provides());
    }

    private function laravel(): Application
    {
        $app = $this->app;

        $this->assertInstanceOf(Application::class, $app);

        return $app;
    }
}
