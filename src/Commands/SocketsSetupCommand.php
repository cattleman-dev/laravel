<?php

namespace Cattleman\Laravel\Commands;

use Cattleman\Laravel\Support\BroadcastingConfig;
use Cattleman\Laravel\Support\ChannelRoutes;
use Cattleman\Laravel\Support\EnvFile;
use Cattleman\Laravel\Support\SocketsCredentials;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

/**
 * Points the app's broadcasting at a Cattleman Sockets deployment: a
 * "cattleman" connection in config/broadcasting.php, the channel routes,
 * the Pusher PHP SDK, the CATTLEMAN_* variables in .env and .env.example,
 * and optionally Laravel Echo. Every step checks first, so it's safe to run
 * again.
 */
class SocketsSetupCommand extends Command
{
    protected $signature = 'cattleman:sockets
        {--app-id= : The Sockets app ID}
        {--key= : The Sockets app key}
        {--secret= : The Sockets app secret}
        {--host= : The worker\'s host, or its URL}
        {--port= : The worker\'s port (default 443)}
        {--scheme= : https or http (default https)}
        {--with-echo : Also set up Laravel Echo for the frontend}
        {--without-echo : Don\'t set up Laravel Echo}
        {--without-composer : Don\'t install pusher/pusher-php-server}
        {--without-node : Don\'t install laravel-echo and pusher-js}
        {--composer=composer : The Composer binary to use}
        {--force : Overwrite existing CATTLEMAN_* values in .env without asking}';

    protected $description = 'Set up broadcasting through a Cattleman Sockets deployment';

    private const PUSHER_SDK = 'pusher/pusher-php-server';

    /** Broadcast connections it's fine to switch away from without asking. */
    private const REPLACEABLE_CONNECTIONS = ['', 'null', 'log', 'cattleman'];

    public function handle(): int
    {
        $this->components->info('Setting up Cattleman Sockets.');

        $credentials = $this->credentials();

        if ($credentials === null) {
            return self::FAILURE;
        }

        $this->configureBroadcasting();
        $this->configureChannelRoutes();
        $this->installPusherSdk();
        $this->writeEnv($credentials);

        if ($this->wantsEcho()) {
            $this->configureEcho();
        }

        $this->newLine();
        $this->components->info('Sockets is set up. Broadcast events now go through your Cattleman worker.');
        $this->components->bulletList([
            'Check the connection: <fg=cyan>php artisan cattleman:sockets:check</>',
            'If your config is cached, run <fg=cyan>php artisan config:clear</>',
            'Set the same CATTLEMAN_* variables wherever the app is deployed',
        ]);

        return self::SUCCESS;
    }

    private function credentials(): ?SocketsCredentials
    {
        $values = array_filter([
            'appId' => $this->option('app-id'),
            'key' => $this->option('key'),
            'secret' => $this->option('secret'),
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'scheme' => $this->option('scheme'),
        ], fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        $credentials = SocketsCredentials::make(...$values);

        if ($credentials->missing() === []) {
            return $credentials;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Missing '.implode(', ', $credentials->missing()).'. Pass --app-id, --key, --secret and --host.');

            return null;
        }

        $this->components->info('The app ID, key, secret and host are on the deployment\'s page in Cattleman, under Credentials.');

        $method = select(
            label: 'How would you like to enter them?',
            options: [
                'paste' => 'Paste a .env block (CATTLEMAN_* or PUSHER_* variables)',
                'prompt' => 'Enter each value',
            ],
        );

        if ($method === 'paste') {
            // Options given on the command line win over the paste.
            $values += SocketsCredentials::parseEnvBlock(textarea(
                label: 'Paste the .env block',
                placeholder: "CATTLEMAN_APP_ID=...\nCATTLEMAN_APP_KEY=...\nCATTLEMAN_APP_SECRET=...\nCATTLEMAN_HOST=...",
                hint: 'PUSHER_* names work too.',
            ));
        }

        // Ask for whatever is still missing.
        $values['appId'] ??= text('App ID', required: true);
        $values['key'] ??= text('App key', required: true);
        $values['secret'] ??= password('App secret', required: true);
        $values['host'] ??= text(
            label: 'Worker host',
            placeholder: 'your-sockets.your-subdomain.workers.dev',
            required: true,
            hint: 'The hostname or URL of your Sockets worker, or its custom domain.',
        );

        return SocketsCredentials::make(...$values);
    }

    private function configureBroadcasting(): void
    {
        $config = new BroadcastingConfig($this->laravel->configPath('broadcasting.php'));

        if (! $config->exists()) {
            $this->callSilently('config:publish', ['name' => 'broadcasting']);
            $this->components->task('Publishing config/broadcasting.php');
        }

        if ($config->hasConnection()) {
            $this->components->twoColumnDetail('The "cattleman" broadcast connection', '<fg=gray>ALREADY THERE</>');

            return;
        }

        if ($config->addConnection()) {
            $this->components->task('Adding the "cattleman" connection to config/broadcasting.php');

            return;
        }

        $this->components->warn('Couldn\'t find the "connections" array in config/broadcasting.php. Add this to it:');
        $this->line(BroadcastingConfig::CONNECTION);
    }

    private function configureChannelRoutes(): void
    {
        $routes = new ChannelRoutes(
            $this->laravel->basePath('routes/channels.php'),
            $this->laravel->bootstrapPath('app.php'),
            dirname(__DIR__, 2).'/stubs/channels.php',
        );

        if ($routes->ensureRoutesFile()) {
            $this->components->task('Creating routes/channels.php');
        }

        match ($routes->register()) {
            'registered' => $this->components->task('Registering the channel routes in bootstrap/app.php'),
            'already' => null,
            'failed' => $this->components->warn("Couldn't register routes/channels.php in bootstrap/app.php. Add channels: __DIR__.'/../routes/channels.php' to its withRouting() call."),
        };
    }

    private function installPusherSdk(): void
    {
        if (InstalledVersions::isInstalled(self::PUSHER_SDK)) {
            return;
        }

        $command = [$this->option('composer'), 'require', self::PUSHER_SDK];

        if ($this->option('without-composer')
            || ($this->input->isInteractive() && ! confirm('Laravel needs '.self::PUSHER_SDK.' to broadcast. Install it now?', default: true))) {
            $this->components->warn('Install it yourself before broadcasting: '.implode(' ', $command));

            return;
        }

        $this->components->info('Installing '.self::PUSHER_SDK.'.');

        $result = Process::path($this->laravel->basePath())
            ->timeout(600)
            ->run($command, fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->warn('Composer didn\'t finish. Run it yourself: '.implode(' ', $command));
        }
    }

    private function writeEnv(SocketsCredentials $credentials): void
    {
        $env = new EnvFile($this->laravel->basePath('.env'));
        $example = new EnvFile($this->laravel->basePath('.env.example'));

        if (! $env->exists()) {
            $this->components->warn('There\'s no .env file, so only .env.example was updated. Add the CATTLEMAN_* values wherever the app reads its environment.');
        } else {
            $connection = $env->get('BROADCAST_CONNECTION');
            $switch = in_array((string) $connection, self::REPLACEABLE_CONNECTIONS, true)
                || $this->option('force')
                || ($this->input->isInteractive() && confirm(
                    "Broadcasting currently uses \"{$connection}\". Switch it to Cattleman?",
                    default: true,
                ));

            $result = $env->write(
                ($switch ? ['BROADCAST_CONNECTION' => 'cattleman'] : []) + $credentials->toEnv(),
                fn (string $key, string $current, string $new): bool => $key === 'BROADCAST_CONNECTION'
                    || $this->option('force')
                    || ($this->input->isInteractive() && confirm("{$key} is already set. Replace it?", default: true)),
            );

            $this->components->task('Writing the Sockets settings to .env');
            $this->reportKept($result);

            if (! $switch) {
                $this->components->warn('BROADCAST_CONNECTION was left as "'.$connection.'". Broadcasts go through Cattleman only on the "cattleman" connection.');
            }
        }

        if ($example->exists()) {
            $example->write(['BROADCAST_CONNECTION' => 'cattleman'], overwrite: true);
            $example->write([
                'CATTLEMAN_APP_ID' => '',
                'CATTLEMAN_APP_KEY' => '',
                'CATTLEMAN_APP_SECRET' => '',
                'CATTLEMAN_HOST' => '',
                'CATTLEMAN_PORT' => '443',
                'CATTLEMAN_SCHEME' => 'https',
            ]);
            $this->components->task('Adding the CATTLEMAN_* variables to .env.example');
        }
    }

    private function wantsEcho(): bool
    {
        if ($this->option('with-echo')) {
            return true;
        }

        if ($this->option('without-echo') || ! $this->input->isInteractive()) {
            return false;
        }

        return confirm('Set up Laravel Echo so the frontend can listen too?', default: true);
    }

    private function configureEcho(): void
    {
        $vite = [
            'VITE_CATTLEMAN_APP_KEY' => '${CATTLEMAN_APP_KEY}',
            'VITE_CATTLEMAN_HOST' => '${CATTLEMAN_HOST}',
            'VITE_CATTLEMAN_PORT' => '${CATTLEMAN_PORT}',
            'VITE_CATTLEMAN_SCHEME' => '${CATTLEMAN_SCHEME}',
        ];

        foreach (['.env', '.env.example'] as $file) {
            $env = new EnvFile($this->laravel->basePath($file));

            if ($env->exists()) {
                $env->write($vite);
            }
        }

        $this->components->task('Adding the VITE_CATTLEMAN_* variables');

        $this->writeEchoScript();
        $this->installNodePackages();
    }

    private function writeEchoScript(): void
    {
        $echoPath = $this->laravel->resourcePath('js/echo.js');
        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/echo.js');

        if (is_file($echoPath)) {
            if (! str_contains((string) file_get_contents($echoPath), 'VITE_CATTLEMAN_')) {
                $this->components->warn('resources/js/echo.js already exists, so it was left alone. Point Echo at Cattleman like this:');
                $this->line($stub);
            }

            return;
        }

        if ($this->usesFrameworkEcho()) {
            $this->components->warn('This app configures Echo with configureEcho(). Pass it these options:');
            $this->line(<<<'JS'
            configureEcho({
                broadcaster: 'pusher',
                key: import.meta.env.VITE_CATTLEMAN_APP_KEY,
                cluster: 'mt1',
                wsHost: import.meta.env.VITE_CATTLEMAN_HOST,
                wsPort: import.meta.env.VITE_CATTLEMAN_PORT ?? 443,
                wssPort: import.meta.env.VITE_CATTLEMAN_PORT ?? 443,
                forceTLS: (import.meta.env.VITE_CATTLEMAN_SCHEME ?? 'https') === 'https',
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
            });
            JS);

            return;
        }

        if (! is_dir(dirname($echoPath))) {
            mkdir(dirname($echoPath), 0755, true);
        }

        file_put_contents($echoPath, $stub);
        $this->components->task('Creating resources/js/echo.js');

        foreach (['js/bootstrap.js', 'js/app.js', 'js/bootstrap.ts', 'js/app.ts'] as $entry) {
            $path = $this->laravel->resourcePath($entry);

            if (! is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (! preg_match('/[\'"]\.\/echo(\.js)?[\'"]/', $contents)) {
                file_put_contents($path, rtrim($contents).PHP_EOL.PHP_EOL."import './echo';".PHP_EOL);
                $this->components->task("Importing it from resources/{$entry}");
            }

            return;
        }

        $this->components->warn("Import resources/js/echo.js from your frontend entry point: import './echo';");
    }

    private function usesFrameworkEcho(): bool
    {
        $packageJson = $this->laravel->basePath('package.json');

        if (! is_file($packageJson)) {
            return false;
        }

        $package = json_decode((string) file_get_contents($packageJson), true) ?: [];
        $dependencies = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);

        return isset($dependencies['@laravel/echo-vue']) || isset($dependencies['@laravel/echo-react']);
    }

    private function installNodePackages(): void
    {
        $packageJson = $this->laravel->basePath('package.json');

        if (! is_file($packageJson)) {
            return;
        }

        $package = json_decode((string) file_get_contents($packageJson), true) ?: [];
        $dependencies = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);
        $missing = array_values(array_diff(['laravel-echo', 'pusher-js'], array_keys($dependencies)));

        if ($missing === []) {
            return;
        }

        $command = match (true) {
            is_file($this->laravel->basePath('pnpm-lock.yaml')) => ['pnpm', 'add', '--save-dev'],
            is_file($this->laravel->basePath('yarn.lock')) => ['yarn', 'add', '--dev'],
            is_file($this->laravel->basePath('bun.lock')), is_file($this->laravel->basePath('bun.lockb')) => ['bun', 'add', '--dev'],
            default => ['npm', 'install', '--save-dev'],
        };
        $command = [...$command, ...$missing];

        if ($this->option('without-node')
            || ! $this->input->isInteractive()
            || ! confirm('Install '.implode(' and ', $missing).' now?', default: true)) {
            $this->components->warn('Install them yourself, then rebuild your assets: '.implode(' ', $command));

            return;
        }

        $result = Process::path($this->laravel->basePath())
            ->timeout(600)
            ->run($command, fn (string $type, string $output) => $this->output->write($output));

        $result->failed()
            ? $this->components->warn('That didn\'t finish. Run it yourself: '.implode(' ', $command))
            : $this->components->info('Installed. Rebuild your assets (npm run build) to pick up Echo.');
    }

    /**
     * @param  array<string, string>  $result
     */
    private function reportKept(array $result): void
    {
        $kept = array_keys(array_filter($result, fn (string $status): bool => $status === 'kept'));

        if ($kept !== []) {
            $this->components->warn('Kept the existing '.implode(', ', $kept).'. Pass --force to replace them.');
        }
    }
}
