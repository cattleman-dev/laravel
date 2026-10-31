<?php

namespace Cattleman\Laravel\Support;

/**
 * Adds the "cattleman" connection to config/broadcasting.php. It's the
 * framework's own Pusher driver pointed at the Sockets worker, so nothing
 * custom is needed at runtime.
 */
class BroadcastingConfig
{
    public const CONNECTION = <<<'PHP'
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
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],
PHP;

    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function hasConnection(): bool
    {
        return $this->exists()
            && preg_match('/[\'"]cattleman[\'"]\s*=>/', (string) file_get_contents($this->path)) === 1;
    }

    /**
     * Insert the connection at the top of the "connections" array. False when
     * that array can't be found, so the caller can show the snippet instead.
     */
    public function addConnection(): bool
    {
        if ($this->hasConnection()) {
            return true;
        }

        $contents = (string) file_get_contents($this->path);

        if (! preg_match('/([\'"]connections[\'"]\s*=>\s*(?:\[|array\())[^\S\r\n]*(\r?\n)/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $eol = $matches[2][0];
        $insertAt = $matches[0][1] + strlen($matches[0][0]);
        $block = $eol.str_replace("\n", $eol, self::CONNECTION).$eol;

        file_put_contents($this->path, substr($contents, 0, $insertAt).$block.substr($contents, $insertAt));

        return true;
    }
}
