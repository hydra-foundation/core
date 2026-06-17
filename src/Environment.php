<?php

declare(strict_types=1);

namespace Hydra\Core;

final class Environment
{
    private array $data = [];

    public function __construct(private readonly string $basePath)
    {
        $this->load();
    }

    private function load(): void
    {
        $path = $this->basePath . '/.env';

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim(trim($value), "\"'");

            $this->data[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data[$key] ?? getenv($key);

        return $value === false ? $default : $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]) || getenv($key) !== false;
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        // A present-but-non-integer value is a config error, not a 0. Failing
        // here beats silently coercing "abc" (or "") to 0 deep in the app.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                "Environment value for \"{$key}\" must be an integer, got \"{$value}\"."
            );
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            'true', '1', 'yes', 'on' => true,
            default => false,
        };
    }
}
