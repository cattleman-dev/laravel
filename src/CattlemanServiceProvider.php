<?php

namespace Cattleman\Laravel;

use Cattleman\Laravel\Commands\CattlemanCommand;
use Cattleman\Laravel\Commands\SocketsCheckCommand;
use Cattleman\Laravel\Commands\SocketsSetupCommand;
use Illuminate\Support\ServiceProvider;

class CattlemanServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CattlemanCommand::class,
                SocketsSetupCommand::class,
                SocketsCheckCommand::class,
            ]);
        }
    }
}
