<?php

namespace Cattleman\Laravel\Tests\Feature;

use Cattleman\Laravel\Tests\TestCase;

class SocketsSetupCommandTest extends TestCase
{
    private const OPTIONS = [
        '--app-id' => '123',
        '--key' => 'app-key',
        '--secret' => 'app-secret',
        '--host' => 'https://my-sockets.example.workers.dev',
        '--without-node' => true,
        '--no-interaction' => true,
    ];

    public function testSetsUpBroadcastingAndEcho(): void
    {
        $this->artisan('cattleman:sockets', self::OPTIONS + ['--with-echo' => true])->assertSuccessful();

        $config = $this->read('config/broadcasting.php');
        $this->assertStringContainsString("'cattleman' => [", $config);
        $this->assertStringContainsString("env('CATTLEMAN_APP_KEY')", $config);
        $this->assertSame(1, substr_count($config, "'connections' =>"));

        $this->assertFileExists($this->path('routes/channels.php'));
        $this->assertStringContainsString(
            "commands: __DIR__.'/../routes/console.php',\n        channels: __DIR__.'/../routes/channels.php',",
            $this->read('bootstrap/app.php'),
        );

        $env = $this->read('.env');
        $this->assertStringContainsString('BROADCAST_CONNECTION=cattleman', $env);
        $this->assertStringContainsString("CATTLEMAN_APP_ID=123\nCATTLEMAN_APP_KEY=app-key\nCATTLEMAN_APP_SECRET=app-secret\nCATTLEMAN_HOST=my-sockets.example.workers.dev\nCATTLEMAN_PORT=443\nCATTLEMAN_SCHEME=https", $env);
        $this->assertStringContainsString('VITE_CATTLEMAN_APP_KEY=${CATTLEMAN_APP_KEY}', $env);

        $example = $this->read('.env.example');
        $this->assertStringContainsString('BROADCAST_CONNECTION=cattleman', $example);
        $this->assertStringContainsString("CATTLEMAN_APP_ID=\nCATTLEMAN_APP_KEY=\nCATTLEMAN_APP_SECRET=\nCATTLEMAN_HOST=\nCATTLEMAN_PORT=443\nCATTLEMAN_SCHEME=https", $example);
        $this->assertStringNotContainsString('app-secret', $example);
        $this->assertStringContainsString('VITE_CATTLEMAN_HOST=${CATTLEMAN_HOST}', $example);

        $this->assertStringContainsString('VITE_CATTLEMAN_APP_KEY', $this->read('resources/js/echo.js'));
        $this->assertStringEndsWith("import './echo';\n", $this->read('resources/js/bootstrap.js'));
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $this->artisan('cattleman:sockets', self::OPTIONS + ['--with-echo' => true])->assertSuccessful();
        $files = array_map(fn (string $file) => $this->read($file), $paths = ['.env', '.env.example', 'config/broadcasting.php', 'bootstrap/app.php', 'resources/js/bootstrap.js', 'resources/js/echo.js']);

        $this->artisan('cattleman:sockets', self::OPTIONS + ['--with-echo' => true])
            ->expectsOutputToContain('ALREADY THERE')
            ->assertSuccessful();

        $this->assertSame($files, array_map(fn (string $file) => $this->read($file), $paths));
    }

    public function testFailsWithoutCredentialsWhenNotInteractive(): void
    {
        $this->artisan('cattleman:sockets', ['--app-id' => '1', '--no-interaction' => true])
            ->expectsOutputToContain('Missing app key, app secret, host')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->path('config/broadcasting.php'));
    }

    public function testKeepsAnotherBroadcasterAndExistingValuesUnlessForced(): void
    {
        file_put_contents($this->path('.env'), "BROADCAST_CONNECTION=reverb\nCATTLEMAN_APP_SECRET=old\n");

        $this->artisan('cattleman:sockets', self::OPTIONS + ['--without-echo' => true])
            ->expectsOutputToContain('Kept the existing CATTLEMAN_APP_SECRET')
            ->expectsOutputToContain('BROADCAST_CONNECTION was left as "reverb"')
            ->assertSuccessful();

        $this->assertStringContainsString("BROADCAST_CONNECTION=reverb\nCATTLEMAN_APP_SECRET=old", $this->read('.env'));

        $this->artisan('cattleman:sockets', self::OPTIONS + ['--without-echo' => true, '--force' => true])->assertSuccessful();

        $this->assertStringContainsString("BROADCAST_CONNECTION=cattleman\nCATTLEMAN_APP_SECRET=app-secret", $this->read('.env'));
    }

    public function testAnExistingEchoScriptIsLeftAlone(): void
    {
        file_put_contents($this->path('resources/js/echo.js'), "// reverb\n");

        $this->artisan('cattleman:sockets', self::OPTIONS + ['--with-echo' => true])
            ->expectsOutputToContain('resources/js/echo.js already exists')
            ->assertSuccessful();

        $this->assertSame("// reverb\n", $this->read('resources/js/echo.js'));
        $this->assertStringNotContainsString('./echo', $this->read('resources/js/bootstrap.js'));
    }

    public function testInteractivelyWithAPastedBlock(): void
    {
        $this->artisan('cattleman:sockets')
            ->expectsQuestion('How would you like to enter them?', 'paste')
            ->expectsQuestion('Paste the .env block', "PUSHER_APP_ID=9\nPUSHER_APP_KEY=k\nPUSHER_APP_SECRET=s\nPUSHER_HOST=ws.example.com\nPUSHER_PORT=8443")
            ->expectsConfirmation('Set up Laravel Echo so the frontend can listen too?', 'no')
            ->assertSuccessful();

        $this->assertStringContainsString("CATTLEMAN_APP_ID=9\nCATTLEMAN_APP_KEY=k\nCATTLEMAN_APP_SECRET=s\nCATTLEMAN_HOST=ws.example.com\nCATTLEMAN_PORT=8443", $this->read('.env'));
        $this->assertFileDoesNotExist($this->path('resources/js/echo.js'));
    }

    public function testInteractivelyAsksForWhatThePasteLacked(): void
    {
        $this->artisan('cattleman:sockets', ['--without-echo' => true])
            ->expectsQuestion('How would you like to enter them?', 'paste')
            ->expectsQuestion('Paste the .env block', "CATTLEMAN_APP_ID=9\nCATTLEMAN_APP_KEY=k")
            ->expectsQuestion('App secret', 's')
            ->expectsQuestion('Worker host', 'ws.example.com')
            ->assertSuccessful();

        $this->assertStringContainsString('CATTLEMAN_APP_SECRET=s', $this->read('.env'));
    }
}
