<?php

declare(strict_types=1);

namespace Hydra\Core;

/**
 * Reads `<basePath>/.env` once, at construction, and exposes typed accessors.
 *
 * Precedence: the real process environment wins. A variable already set in
 * $_ENV, $_SERVER, or the OS environment (getenv) — by a container runtime,
 * the web server, or an `export` — overrides the `.env` file's value, so a
 * deployment can override checked-in defaults without editing the file.
 * `.env` values fill the gaps and are exported to $_ENV/putenv for code that
 * reads those directly, but never clobber a variable the process already has.
 *
 * Parsing the file is real work done in the constructor, so construct this
 * exactly once per process and share the instance — Hydra's composition root
 * builds one and binds it in the container. There is deliberately no static
 * cache here: a hidden singleton would be magic; the single construction is
 * explicit in the bootstrap instead.
 */
final class Environment
{
    /** @var array<string, string> values parsed from the .env file */
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

            // The real process environment beats the .env file: skip the
            // file's value entirely so it neither shadows nor exports over a
            // variable the process already has.
            if ($this->fromProcess($key) !== null) {
                continue;
            }

            $value = $this->parseValue(trim($value));

            $this->data[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * The value from the real process environment ($_ENV, $_SERVER, getenv),
     * or null when not set there. Only string values count — $_SERVER also
     * holds non-environment entries such as the `argv` array.
     */
    private function fromProcess(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    /**
     * Normalizes a raw .env value.
     *
     * Quoted values ("..." or '...') are taken literally with exactly one
     * matching pair of quotes removed — a `#` inside quotes is data, not a
     * comment, so URLs with fragments survive. A blind trim($value, "\"'")
     * would also strip MISMATCHED quotes ("foo' → foo), silently corrupting
     * values, so only a same-character pair is removed.
     *
     * Unquoted values may carry inline comments (`APP_DEBUG=true # prod: false`);
     * everything from the first whitespace-then-# is dropped so the comment
     * never leaks into the value. A value that is only a comment becomes "".
     */
    private function parseValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return substr($value, 1, -1);
            }
        }

        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;

        // `KEY= # comment` leaves a bare "#..." with no leading whitespace
        // after the '=' trim; that is still a comment, not a value.
        if (str_starts_with($value, '#')) {
            return '';
        }

        return rtrim($value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Real process environment first, then the .env file — same precedence
        // load() applies, and it also covers variables set after construction.
        return $this->fromProcess($key) ?? $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->fromProcess($key) !== null || isset($this->data[$key]);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /**
     * The value for $key, which must be set and non-empty.
     *
     * Fail-fast accessor for configuration the app cannot run without
     * (credentials, signing keys, DSNs): an unset key — or one set to an
     * empty string, which for required config is the same misconfiguration —
     * throws here, at boot, instead of surfacing as a broken null/"" deep in
     * the app.
     *
     * @throws \RuntimeException when the key is unset or empty.
     */
    public function required(string $key): string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException(
                "Required environment variable \"{$key}\" is not set."
            );
        }

        return (string) $value;
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

    /**
     * Accepted forms, case-insensitive: `true`/`false`, `1`/`0`, `yes`/`no`,
     * `on`/`off`. A missing key returns $default; any other present value
     * (including an empty string) throws — a mistyped boolean is a config
     * error, not a silent false, same policy as {@see int()}.
     *
     * @throws \InvalidArgumentException when the value is not boolean-ish.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new \InvalidArgumentException(
                "Environment value for \"{$key}\" must be a boolean"
                . " (true/false, 1/0, yes/no, on/off), got \"{$value}\"."
            ),
        };
    }
}
