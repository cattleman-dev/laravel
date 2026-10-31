<?php

namespace Cattleman\Laravel\Tests\Feature;

use Cattleman\Laravel\Tests\TestCase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class SocketsCheckCommandTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    public function testFailsWithoutTheConnection(): void
    {
        $this->artisan('cattleman:sockets:check')
            ->expectsOutputToContain('There\'s no "cattleman" broadcast connection')
            ->assertFailed();
    }

    public function testNamesTheMissingValues(): void
    {
        $this->connection([new Response(200)], ['secret' => null]);

        $this->artisan('cattleman:sockets:check')
            ->expectsOutputToContain('Not set: CATTLEMAN_APP_SECRET')
            ->assertFailed();
    }

    public function testAcceptedCredentialsPass(): void
    {
        $this->connection([new Response(200, [], '{"channels":{"orders":{}}}'), new Response(200, [], '{}')]);
        config(['broadcasting.default' => 'cattleman']);

        $this->artisan('cattleman:sockets:check', ['--trigger' => true])
            ->expectsOutputToContain('ACCEPTED')
            ->expectsOutputToContain('SENT')
            ->assertSuccessful();

        $this->assertSame('https://ws.example.com/apps/123/channels', (string) $this->sent[0]['request']->getUri()->withQuery(''));
        $this->assertSame('/apps/123/events', $this->sent[1]['request']->getUri()->getPath());
    }

    public function testRefusedCredentialsAreExplained(): void
    {
        $this->connection([new Response(401, [], 'Invalid signature')]);

        $this->artisan('cattleman:sockets:check')
            ->expectsOutputToContain('The worker refused the credentials (401)')
            ->assertFailed();
    }

    public function testWarnsWhenTheAppBroadcastsElsewhere(): void
    {
        $this->connection([new Response(200, [], '{"channels":{}}')]);
        config(['broadcasting.default' => 'log']);

        $this->artisan('cattleman:sockets:check')
            ->expectsOutputToContain('the app broadcasts through "log"')
            ->assertSuccessful();
    }

    /**
     * @param  list<Response>  $responses
     * @param  array<string, mixed>  $overrides
     */
    private function connection(array $responses, array $overrides = []): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        config(['broadcasting.connections.cattleman' => array_merge([
            'driver' => 'pusher',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => '123',
            'options' => ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https', 'useTLS' => true],
            'client_options' => ['handler' => $stack],
        ], $overrides)]);
    }
}
