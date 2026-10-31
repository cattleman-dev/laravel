<?php

namespace Cattleman\Laravel\Support;

/**
 * routes/channels.php and its registration in bootstrap/app.php, the same
 * way the framework's install:broadcasting does it.
 */
class ChannelRoutes
{
    private const LINE = "channels: __DIR__.'/../routes/channels.php',";

    public function __construct(
        private readonly string $routesPath,
        private readonly string $bootstrapPath,
        private readonly string $stubPath,
    ) {
    }

    /**
     * @return bool  true when the file was created
     */
    public function ensureRoutesFile(): bool
    {
        if (is_file($this->routesPath)) {
            return false;
        }

        if (! is_dir(dirname($this->routesPath))) {
            mkdir(dirname($this->routesPath), 0755, true);
        }

        copy($this->stubPath, $this->routesPath);

        return true;
    }

    /**
     * @return 'registered'|'already'|'failed'
     */
    public function register(): string
    {
        if (! is_file($this->bootstrapPath)) {
            return 'failed';
        }

        $contents = (string) file_get_contents($this->bootstrapPath);

        if (str_contains($contents, '// channels: ')) {
            $updated = str_replace('// channels: ', 'channels: ', $contents);
        } elseif (str_contains($contents, 'channels: ')) {
            return 'already';
        } elseif (preg_match("/^([^\S\r\n]*)commands: __DIR__\\.'\\/\\.\\.\\/routes\\/console\\.php',/m", $contents, $matches)) {
            $updated = str_replace($matches[0], $matches[0].PHP_EOL.$matches[1].self::LINE, $contents);
        } elseif (str_contains($contents, '->withRouting(')) {
            $updated = str_replace('->withRouting(', '->withRouting('.PHP_EOL.'        '.self::LINE, $contents);
        } else {
            return 'failed';
        }

        file_put_contents($this->bootstrapPath, $updated);

        return 'registered';
    }
}
