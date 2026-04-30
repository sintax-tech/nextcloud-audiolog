<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCP\Security\ICrypto;

/**
 * Wraps OCP\Security\ICrypto so callers don't have to think about migration
 * from legacy plain-text values stored before this app encrypted them.
 *
 * Strategy: encrypted values are tagged with the prefix `enc:v1:` followed by
 * base64. Anything without that prefix is treated as plain text (legacy).
 * decrypt() is a no-op for legacy values, so reads keep working during the
 * transition; saveSettings() always re-saves through encrypt(), migrating
 * the value on the next admin save.
 */
class CryptoHelper {
    private const PREFIX = 'enc:v1:';

    public function __construct(
        private ICrypto $crypto
    ) {
    }

    public function encrypt(string $plain): string {
        if ($plain === '') {
            return '';
        }
        return self::PREFIX . base64_encode($this->crypto->encrypt($plain));
    }

    public function decrypt(string $stored): string {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored; // legacy plain value
        }
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($payload === false) {
            return '';
        }
        try {
            return $this->crypto->decrypt($payload);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
