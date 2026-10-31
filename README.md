# Cattleman for Laravel

Artisan commands that set up [Cattleman](https://cattleman.dev) modules in a
Laravel 11 or 12 app. Install it as a dev dependency:

```bash
composer require --dev cattleman-dev/laravel
```

Then open the palette:

```bash
php artisan cattleman
```

## Sockets

```bash
php artisan cattleman:sockets
```

Points the app's broadcasting at your Cattleman Sockets deployment. It asks
for the deployment's credentials, from its page in Cattleman: enter each
value, or paste a `.env` block with `CATTLEMAN_*` or `PUSHER_*` variables
(handy when moving an app off Pusher). Then it:

- publishes `config/broadcasting.php` if it isn't there, and adds a
  `cattleman` connection: Laravel's own Pusher driver, pointed at your worker;
- creates `routes/channels.php` and registers it in `bootstrap/app.php`;
- installs `pusher/pusher-php-server`, which the Pusher driver needs (as a
  normal dependency);
- sets `BROADCAST_CONNECTION=cattleman` and the `CATTLEMAN_*` values in
  `.env`, and adds the same keys, without secrets, to `.env.example`;
- optionally sets up Laravel Echo: the `VITE_CATTLEMAN_*` variables,
  `resources/js/echo.js`, and the `laravel-echo` and `pusher-js` packages.

Every step checks first, so running it again is safe. It never replaces an
existing value or another broadcaster without asking (or `--force`), and it
leaves an existing `echo.js` alone, printing the settings to use instead.

Without prompts, for scripts and CI:

```bash
php artisan cattleman:sockets --no-interaction \
    --app-id=123 --key=your-key --secret=your-secret \
    --host=https://your-sockets.your-subdomain.workers.dev \
    --with-echo --without-node
```

| Option | |
|---|---|
| `--app-id`, `--key`, `--secret` | The deployment's credentials |
| `--host` | The worker's hostname or URL (a URL sets `--scheme` and `--port` too) |
| `--port`, `--scheme` | Default `443` and `https` |
| `--with-echo` / `--without-echo` | Set up Laravel Echo, or don't ask |
| `--without-composer` | Don't install `pusher/pusher-php-server` |
| `--without-node` | Don't install `laravel-echo` and `pusher-js` |
| `--composer` | The Composer binary to use |
| `--force` | Replace existing values without asking |

### Checking the connection

```bash
php artisan cattleman:sockets:check
```

Confirms the `cattleman` connection is configured, that the app broadcasts
through it, and that the worker accepts the credentials. `--trigger` also
sends a test event to the `cattleman-check` channel.

The connection it adds:

```php
'cattleman' => [
    'driver' => 'pusher',
    'key' => env('CATTLEMAN_APP_KEY'),
    'secret' => env('CATTLEMAN_APP_SECRET'),
    'app_id' => env('CATTLEMAN_APP_ID'),
    'options' => [
        'host' => env('CATTLEMAN_HOST'),
        'port' => env('CATTLEMAN_PORT', 443),
        'scheme' => env('CATTLEMAN_SCHEME', 'https'),
        'encrypted' => true,
        'useTLS' => env('CATTLEMAN_SCHEME', 'https') === 'https',
    ],
],
```

## Development

```bash
composer install
vendor/bin/phpunit
```

## Licence

MIT. See [LICENSE](LICENSE).
