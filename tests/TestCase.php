<?php

namespace Cattleman\Laravel\Tests;

use Cattleman\Laravel\CattlemanServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Runs each test against a throwaway copy of a Laravel 11/12 app skeleton,
 * so the commands can write to .env, config and bootstrap/app.php freely.
 */
abstract class TestCase extends Orchestra
{
    protected string $app_path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app_path = sys_get_temp_dir().'/cattleman-laravel-'.bin2hex(random_bytes(6));
        $this->makeSkeleton($this->app_path);

        $this->app->setBasePath($this->app_path);
        $this->app->useConfigPath($this->app_path.'/config');
        $this->app->useBootstrapPath($this->app_path.'/bootstrap');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->app_path);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [CattlemanServiceProvider::class];
    }

    protected function path(string $path): string
    {
        return $this->app_path.'/'.$path;
    }

    protected function read(string $path): string
    {
        return (string) file_get_contents($this->path($path));
    }

    private function makeSkeleton(string $path): void
    {
        foreach (['bootstrap', 'config', 'routes', 'resources/js'] as $directory) {
            mkdir($path.'/'.$directory, 0755, true);
        }

        file_put_contents($path.'/bootstrap/app.php', <<<'PHP'
        <?php

        use Illuminate\Foundation\Application;
        use Illuminate\Foundation\Configuration\Exceptions;
        use Illuminate\Foundation\Configuration\Middleware;

        return Application::configure(basePath: dirname(__DIR__))
            ->withRouting(
                web: __DIR__.'/../routes/web.php',
                commands: __DIR__.'/../routes/console.php',
                health: '/up',
            )
            ->withMiddleware(function (Middleware $middleware): void {
                //
            })
            ->withExceptions(function (Exceptions $exceptions): void {
                //
            })->create();

        PHP);

        $env = <<<'ENV'
        APP_NAME=Laravel
        APP_ENV=local

        BROADCAST_CONNECTION=log
        FILESYSTEM_DISK=local
        QUEUE_CONNECTION=database

        VITE_APP_NAME="${APP_NAME}"

        ENV;

        file_put_contents($path.'/.env', $env);
        file_put_contents($path.'/.env.example', $env);
        file_put_contents($path.'/resources/js/app.js', "import './bootstrap';\n");
        file_put_contents($path.'/resources/js/bootstrap.js', "import axios from 'axios';\nwindow.axios = axios;\n");
        file_put_contents($path.'/package.json', json_encode(['private' => true, 'devDependencies' => ['vite' => '^6.0']], JSON_PRETTY_PRINT));
    }
}
