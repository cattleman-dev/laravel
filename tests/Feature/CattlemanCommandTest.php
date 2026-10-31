<?php

namespace Cattleman\Laravel\Tests\Feature;

use Cattleman\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class CattlemanCommandTest extends TestCase
{
    public function testListsTheCommandsWhenNotInteractive(): void
    {
        $this->assertSame(0, Artisan::call('cattleman', ['--no-interaction' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('cattleman:sockets ', $output);
        $this->assertStringContainsString('cattleman:sockets:check', $output);
        $this->assertStringContainsString('Sockets: check the connection', $output);
    }

    public function testRunsTheChosenCommand(): void
    {
        $this->artisan('cattleman')
            ->expectsQuestion('What would you like to do?', 'cattleman:sockets:check')
            ->expectsOutputToContain('There\'s no "cattleman" broadcast connection')
            ->assertFailed();
    }
}
