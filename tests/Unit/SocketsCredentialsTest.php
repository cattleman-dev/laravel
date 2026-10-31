<?php

namespace Cattleman\Laravel\Tests\Unit;

use Cattleman\Laravel\Support\SocketsCredentials;
use PHPUnit\Framework\TestCase;

class SocketsCredentialsTest extends TestCase
{
    public function testAHostGivenAsAUrlSetsSchemeAndPort(): void
    {
        $credentials = SocketsCredentials::make('1', 'key', 'secret', 'http://sockets.test:8080/');

        $this->assertSame('sockets.test', $credentials->host);
        $this->assertSame('http', $credentials->scheme);
        $this->assertSame(8080, $credentials->port);
    }

    public function testAPlainHostDefaultsToHttpsOn443(): void
    {
        $credentials = SocketsCredentials::make('1', 'key', 'secret', 'my-sockets.example.workers.dev/');

        $this->assertSame('my-sockets.example.workers.dev', $credentials->host);
        $this->assertSame('https', $credentials->scheme);
        $this->assertSame(443, $credentials->port);
        $this->assertSame([], $credentials->missing());
    }

    public function testReadsAPastedBlockWithEitherPrefix(): void
    {
        $credentials = SocketsCredentials::fromEnvBlock(<<<'ENV'
        BROADCAST_CONNECTION=pusher
        PUSHER_APP_ID=123
        CATTLEMAN_APP_KEY="abc"
        export PUSHER_APP_SECRET='s3cret'
        PUSHER_HOST=my-sockets.example.workers.dev
        ENV);

        $this->assertSame('123', $credentials->appId);
        $this->assertSame('abc', $credentials->key);
        $this->assertSame('s3cret', $credentials->secret);
        $this->assertSame('my-sockets.example.workers.dev', $credentials->host);
    }

    public function testReportsWhatIsMissing(): void
    {
        $this->assertSame(['app secret', 'host'], SocketsCredentials::make('1', 'key')->missing());
    }
}
