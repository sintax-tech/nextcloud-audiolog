<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCA\Audiolog\AppInfo\Application;
use OCP\IConfig;

/**
 * Masks API keys and tokens in any text that may leave the provider call:
 * log lines, job.error (returned by /api/status) and HTTP responses.
 *
 * Two layers: the literal values of the keys configured in the app (whatever
 * their shape) and generic patterns (`key=` query values, Google `AIza…`
 * keys, OpenAI `sk-…` tokens, `x-goog-api-key`/`Bearer` headers).
 */
class SecretRedactor {
    private const MASK = '***';
    private const SECRET_SETTINGS = ['api_key', 'google_stt_api_key'];

    /** @var list<string>|null */
    private ?array $secrets = null;

    public function __construct(
        private IConfig $config,
        private CryptoHelper $crypto
    ) {
    }

    public function redact(string $text): string {
        if ($text === '') {
            return $text;
        }
        foreach ($this->configuredSecrets() as $secret) {
            $text = str_replace([$secret, rawurlencode($secret)], self::MASK, $text);
        }
        $patterns = [
            '/([?&](?:key|api_key|apikey|access_token)=)[^&\s"\'`]+/i' => '$1' . self::MASK,
            '/(x-goog-api-key["\']?\s*[:=]\s*["\']?)[^\s"\'`,}]+/i' => '$1' . self::MASK,
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i' => '$1' . self::MASK,
            '/AIza[0-9A-Za-z_-]{30,}/' => self::MASK,
            '/\bsk-[A-Za-z0-9_-]{16,}/' => self::MASK,
        ];
        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        return $text;
    }

    /** @return list<string> */
    private function configuredSecrets(): array {
        if ($this->secrets === null) {
            $this->secrets = [];
            foreach (self::SECRET_SETTINGS as $setting) {
                $value = $this->crypto->decrypt($this->config->getAppValue(Application::APP_ID, $setting, ''));
                // Very short values would mask ordinary words; the patterns
                // above still cover the usual key shapes.
                if (strlen($value) >= 8) {
                    $this->secrets[] = $value;
                }
            }
        }
        return $this->secrets;
    }
}
