<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase {
    private SecretRedactor $redactor;

    protected function setUp(): void {
        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('decrypt')->willReturnArgument(0);
        $config = TestHelpers::config($this, [
            'api_key' => 'ollama-token-without-known-shape',
            'google_stt_api_key' => TestHelpers::STT_KEY,
        ]);
        $this->redactor = new SecretRedactor($config, $crypto);
    }

    public function testGuzzleMessageWithKeyInQueryIsMasked(): void {
        $msg = 'Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/m:generateContent?key='
            . TestHelpers::GEMINI_KEY . '&alt=json` resulted in a `400 Bad Request` response';
        $out = $this->redactor->redact($msg);
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $out);
        $this->assertStringContainsString('key=***', $out);
        $this->assertStringContainsString('&alt=json', $out);
    }

    public function testGoogleShapedKeyAnywhereIsMasked(): void {
        $out = $this->redactor->redact('API key not valid: ' . TestHelpers::GEMINI_KEY . '.');
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $out);
    }

    public function testConfiguredSecretsOfAnyShapeAreMasked(): void {
        $out = $this->redactor->redact('upstream said: bad token ollama-token-without-known-shape and ' . TestHelpers::STT_KEY);
        $this->assertStringNotContainsString('ollama-token-without-known-shape', $out);
        $this->assertStringNotContainsString(TestHelpers::STT_KEY, $out);
    }

    public function testHeadersAndBearerAreMasked(): void {
        $out = $this->redactor->redact('x-goog-api-key: abc123secret Authorization: Bearer sk-proj-abcdefghijklmnopqrstuvwxyz');
        $this->assertStringNotContainsString('abc123secret', $out);
        $this->assertStringNotContainsString('sk-proj-abcdefghijklmnopqrstuvwxyz', $out);
    }

    public function testHarmlessMessageIsUntouched(): void {
        $this->assertSame('Arquivo não encontrado', $this->redactor->redact('Arquivo não encontrado'));
    }
}
