<?php

namespace Cattleman\Laravel\Support;

/**
 * Reads and edits a .env-style file line by line, so comments, ordering and
 * every other variable are left exactly as they were. Existing keys are
 * changed in place; new ones are appended as a block at the end.
 */
class EnvFile
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * The value as written (quotes removed, ${...} references left alone),
     * or null when the key isn't in the file.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/', $line, $matches)) {
                return self::unquote(trim($matches[1]));
            }
        }

        return null;
    }

    /**
     * Write the values: existing keys are updated in place (only when
     * $overwrite allows it for that key), missing ones are appended together.
     *
     * @param  array<string, string>  $values
     * @param  bool|callable(string, string, string): bool  $overwrite  true, false, or fn (key, current, new) => bool
     * @return array<string, 'added'|'updated'|'unchanged'|'kept'>  what happened to each key
     */
    public function write(array $values, bool|callable $overwrite = false): array
    {
        $lines = $this->exists() ? $this->lines() : [];
        $result = [];
        $append = [];

        foreach ($values as $key => $value) {
            $index = $this->indexOf($lines, $key);

            if ($index === null) {
                $append[$key] = $value;
                $result[$key] = 'added';

                continue;
            }

            $current = $this->get($key) ?? '';

            if ($current === $value) {
                $result[$key] = 'unchanged';

                continue;
            }

            $allowed = is_callable($overwrite) ? $overwrite($key, $current, $value) : $overwrite;

            if (! $allowed) {
                $result[$key] = 'kept';

                continue;
            }

            $lines[$index] = $key.'='.self::quote($value);
            $result[$key] = 'updated';
        }

        if ($append !== []) {
            while ($lines !== [] && trim(end($lines)) === '') {
                array_pop($lines);
            }

            if ($lines !== []) {
                $lines[] = '';
            }

            foreach ($append as $key => $value) {
                $lines[] = $key.'='.self::quote($value);
            }
        }

        if (array_diff($result, ['unchanged', 'kept']) !== []) {
            file_put_contents($this->path, implode(PHP_EOL, $lines).PHP_EOL);
        }

        return $result;
    }

    public static function quote(string $value): string
    {
        if ($value === '' || ! preg_match('/[\s#"\'\\\\]/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private static function unquote(string $value): string
    {
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $matches)) {
            return stripcslashes($matches[1]);
        }

        if (preg_match("/^'([^']*)'/", $value, $matches)) {
            return $matches[1];
        }

        // Unquoted: an inline comment starts at " #".
        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }

    /**
     * @param  list<string>  $lines
     */
    private function indexOf(array $lines, string $key): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return preg_split('/\r\n|\n|\r/', rtrim((string) file_get_contents($this->path), "\r\n")) ?: [];
    }
}
