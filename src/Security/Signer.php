<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

/**
 * HMAC-SHA256 message signing under an explicitly-injected key.
 *
 * Hydra's one signing mechanism: given a key (APP_KEY, wired in by
 * {@see SignerServiceProvider}), it seals a string so the same key can later
 * prove the string is unchanged and self-minted. The value lives in the
 * signature, not a server-side store — which is what makes it useful for things
 * that leave the server and come back with no counterpart to compare against
 * (CSRF tokens today; signed URLs and cookies as those features arrive).
 *
 * The key is a constructor argument, never read from a global here — the only
 * place APP_KEY is named is the provider. {@see fromHex()} decodes Hydra's
 * canonical hex key format; the raw constructor is for callers that already hold
 * key bytes. A short key is a configuration error and fails loud.
 */
final class Signer
{
    /** SHA-256 HMAC rendered as hex: always 64 chars, so the framing below is fixed-width. */
    private const HMAC_HEX_LEN = 64;

    /** Minimum key material: 32 bytes = 256-bit, matching `key:generate`'s output. */
    private const MIN_KEY_BYTES = 32;

    /**
     * @param non-empty-string       $key          raw key bytes (>= 32)
     * @param list<non-empty-string> $previousKeys raw key bytes tried on verify() only, for rotation
     */
    public function __construct(
        private readonly string $key,
        private readonly array $previousKeys = [],
    ) {
        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Signing key must be at least %d bytes (256-bit); got %d.',
                self::MIN_KEY_BYTES,
                strlen($key),
            ));
        }
    }

    /**
     * Seal a message: "<64-hex-hmac>.<message>". The signature comes first so it
     * is fixed-width — the message that follows may itself contain any bytes,
     * dots included, and {@see verify()} still parses the two apart unambiguously.
     */
    public function sign(string $message): string
    {
        return $this->hmac($message, $this->key) . '.' . $message;
    }

    /**
     * The original message if $signed verifies (constant-time) under the current
     * key or any previous key; otherwise null. Never throws on malformed input —
     * a forged or truncated value is simply not valid, which callers handle the
     * same as any other failed check.
     */
    public function verify(string $signed): ?string
    {
        // "<64 hex>.<message>": at least the signature, the dot, and one byte,
        // with the dot exactly where the fixed-width signature ends.
        if (strlen($signed) < self::HMAC_HEX_LEN + 1 || $signed[self::HMAC_HEX_LEN] !== '.') {
            return null;
        }

        $signature = substr($signed, 0, self::HMAC_HEX_LEN);
        $message = substr($signed, self::HMAC_HEX_LEN + 1);

        foreach ([$this->key, ...$this->previousKeys] as $key) {
            if (hash_equals($this->hmac($message, $key), $signature)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Build a Signer from Hydra's canonical key format: a hex string (64+ chars)
     * decoded to raw bytes. Rejects non-hex, odd-length, or too-short input with
     * an {@see \InvalidArgumentException} naming the fix.
     *
     * @param list<string> $previousHex older keys, same format, for a rotation window
     */
    public static function fromHex(string $hex, array $previousHex = []): self
    {
        return new self(self::decodeHex($hex), array_map(self::decodeHex(...), $previousHex));
    }

    private function hmac(string $message, string $key): string
    {
        return hash_hmac('sha256', $message, $key);
    }

    /** @return non-empty-string */
    private static function decodeHex(string $hex): string
    {
        $raw = @hex2bin($hex); // strict: false on non-hex or odd length

        if ($raw === false || strlen($raw) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException(
                'APP_KEY must be a hex string of at least 64 characters (256-bit); '
                . 'run `php bin/console key:generate` to create one.',
            );
        }

        return $raw;
    }
}
