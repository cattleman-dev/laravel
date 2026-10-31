<?php

namespace Cattleman\Laravel\Commands;

use Composer\InstalledVersions;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks the "cattleman" broadcast connection end to end: the config is
 * there, the app uses it, and the worker accepts the credentials. It asks
 * the worker for its channel list, which needs a correctly signed request
 * but sends nothing to anyone.
 */
class SocketsCheckCommand extends Command
{
    protected $signature = 'cattleman:sockets:check
        {--trigger : Also send a test event to the "cattleman-check" channel}';

    protected $description = 'Check the app can reach its Cattleman Sockets deployment';

    public function handle(BroadcastManager $broadcast): int
    {
        $config = config('broadcasting.connections.cattleman');

        if (! is_array($config)) {
            $this->components->error('There\'s no "cattleman" broadcast connection. Run php artisan cattleman:sockets first.');

            return self::FAILURE;
        }

        $missing = array_keys(array_filter([
            'CATTLEMAN_APP_ID' => $config['app_id'] ?? null,
            'CATTLEMAN_APP_KEY' => $config['key'] ?? null,
            'CATTLEMAN_APP_SECRET' => $config['secret'] ?? null,
            'CATTLEMAN_HOST' => $config['options']['host'] ?? null,
        ], fn (mixed $value): bool => blank($value)));

        if ($missing !== []) {
            $this->components->error('Not set: '.implode(', ', $missing).'. If they\'re in .env, run php artisan config:clear.');

            return self::FAILURE;
        }

        if (! InstalledVersions::isInstalled('pusher/pusher-php-server')) {
            $this->components->error('pusher/pusher-php-server isn\'t installed: composer require pusher/pusher-php-server');

            return self::FAILURE;
        }

        $default = config('broadcasting.default');
        $this->components->twoColumnDetail(
            'Default broadcast connection',
            $default === 'cattleman' ? '<fg=green>cattleman</>' : "<fg=yellow>{$default}</>",
        );

        $host = ($config['options']['scheme'] ?? 'https').'://'.$config['options']['host'].':'.($config['options']['port'] ?? 443);
        $this->components->twoColumnDetail('Worker', $host);

        try {
            /** @var PusherBroadcaster $broadcaster */
            $broadcaster = $broadcast->connection('cattleman');
            $pusher = $broadcaster->getPusher();
            $channels = $pusher->getChannels();
        } catch (Throwable $e) {
            $this->components->error($this->explain($e));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Credentials', '<fg=green>ACCEPTED</>');
        $this->components->twoColumnDetail('Occupied channels', (string) count((array) ($channels->channels ?? [])));

        if ($this->option('trigger')) {
            try {
                $pusher->trigger('cattleman-check', 'check', ['sent_at' => now()->toIso8601String()]);
                $this->components->twoColumnDetail('Test event on "cattleman-check"', '<fg=green>SENT</>');
            } catch (Throwable $e) {
                $this->components->error($this->explain($e));

                return self::FAILURE;
            }
        }

        if ($default !== 'cattleman') {
            $this->components->warn('The worker is reachable, but the app broadcasts through "'.$default.'". Set BROADCAST_CONNECTION=cattleman to use it.');
        } else {
            $this->components->info('Sockets is reachable and accepts your credentials.');
        }

        return self::SUCCESS;
    }

    private function explain(Throwable $e): string
    {
        $status = (int) $e->getCode();

        return match (true) {
            $status === 401, $status === 403 => 'The worker refused the credentials ('.$status.'). Check CATTLEMAN_APP_KEY and CATTLEMAN_APP_SECRET.',
            $status === 404 => 'The worker doesn\'t know that app (404). Check CATTLEMAN_APP_ID and CATTLEMAN_HOST.',
            default => 'Couldn\'t reach the worker: '.$e->getMessage(),
        };
    }
}
