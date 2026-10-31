<?php

namespace Cattleman\Laravel\Support;

/**
 * A Sockets deployment's Pusher credentials and where its worker lives.
 * The host is just the hostname: a pasted URL is split into host, port and
 * scheme.
 */
final class SocketsCredentials
{
    public function __construct(
        public readonly ?string $appId = null,
        public readonly ?string $key = null,
        public readonly ?string $secret = null,
        public readonly ?string $host = null,
        public readonly int $port = 443,
        public readonly string $scheme = 'https',
    ) {
    }

    /**
     * Read a pasted .env block: CATTLEMAN_* or PUSHER_* names, quoted or not.
     * Anything else in the paste is ignored.
     */
    public static function fromEnvBlock(string $block): self
    {
        return self::make(...self::parseEnvBlock($block));
    }

    /**
     * The raw values found in a pasted .env block, keyed like make()'s
     * arguments; anything not in the paste is left out.
     *
     * @return array<'appId'|'key'|'secret'|'host'|'port'|'scheme', string>
     */
    public static function parseEnvBlock(string $block): array
    {
        $names = [
            'APP_ID' => 'appId',
            'APP_KEY' => 'key',
            'APP_SECRET' => 'secret',
            'HOST' => 'host',
            'PORT' => 'port',
            'SCHEME' => 'scheme',
        ];
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?(?:CATTLEMAN|PUSHER)_(APP_ID|APP_KEY|APP_SECRET|HOST|PORT|SCHEME)\s*=\s*(.*?)\s*$/', $line, $matches)) {
                $value = trim($matches[2], '"\'');

                if ($value !== '') {
                    $values[$names[$matches[1]]] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Build from loose input. A host given as a URL sets the scheme and port
     * too, unless they were given separately.
     */
    public static function make(
        ?string $appId = null,
        ?string $key = null,
        ?string $secret = null,
        ?string $host = null,
        int|string|null $port = null,
        ?string $scheme = null,
    ): self {
        $host = self::blankToNull($host);

        if ($host !== null && str_contains($host, '://')) {
            $parts = parse_url($host) ?: [];
            $scheme ??= $parts['scheme'] ?? null;
            $port ??= $parts['port'] ?? null;
            $host = $parts['host'] ?? null;
        } elseif ($host !== null) {
            $host = rtrim(explode('/', $host)[0], '.');
        }

        $scheme = strtolower(self::blankToNull($scheme) ?? 'https');
        $port = self::blankToNull((string) $port);

        return new self(
            appId: self::blankToNull($appId),
            key: self::blankToNull($key),
            secret: self::blankToNull($secret),
            host: $host,
            port: $port !== null ? (int) $port : ($scheme === 'http' ? 80 : 443),
            scheme: $scheme,
        );
    }

    /**
     * @return list<string>  the names of the required values that are missing
     */
    public function missing(): array
    {
        return array_keys(array_filter([
            'app ID' => $this->appId,
            'app key' => $this->key,
            'app secret' => $this->secret,
            'host' => $this->host,
        ], fn (?string $value): bool => $value === null));
    }

    /**
     * @return array<string, string>
     */
    public function toEnv(): array
    {
        return [
            'CATTLEMAN_APP_ID' => (string) $this->appId,
            'CATTLEMAN_APP_KEY' => (string) $this->key,
            'CATTLEMAN_APP_SECRET' => (string) $this->secret,
            'CATTLEMAN_HOST' => (string) $this->host,
            'CATTLEMAN_PORT' => (string) $this->port,
            'CATTLEMAN_SCHEME' => $this->scheme,
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
