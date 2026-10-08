<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\SettingsController;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * M4/B1: "Test connection" and the healthcheck go through Nextcloud's
 * IClient (same proxy and local-address protection as the real processing),
 * so a local Ollama blocked by allow_local_remote_servers is reported instead
 * of a false "OK". No raw cURL (curl_close() is deprecated in PHP 8.5).
 */
class SettingsControllerConnectionTest extends TestCase {
    private array $calls = [];

    private function controller(array $config, IClient $client): SettingsController {
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('decrypt')->willReturnArgument(0);
        $cfg = TestHelpers::config($this, $config);
        return new SettingsController(
            'audiolog', $this->createMock(IRequest::class), $cfg, $crypto,
            $clientService, new SecretRedactor($cfg, $crypto),
            TestHelpers::tempManager($this, sys_get_temp_dir() . '/audiolog_tests_' . getmypid()),
            \OCA\Audiolog\Tests\Unit\Support\TestHelpers::l10n($this),
        );
    }

    private function okClient(int $status = 200): IClient {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $client = $this->createMock(IClient::class);
        $record = function (string $uri, array $options = []) use ($response) {
            $this->calls[] = ['uri' => $uri, 'options' => $options];
            return $response;
        };
        $client->method('get')->willReturnCallback($record);
        $client->method('head')->willReturnCallback($record);
        return $client;
    }

    private function blockedClient(): IClient {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new LocalServerException('Host "localhost" violates local access rules'));
        $client->method('head')->willThrowException(new LocalServerException('Host "localhost" violates local access rules'));
        return $client;
    }

    public function testLocalOllamaBlockedByNextcloudIsReportedWithTheSetting(): void {
        $response = $this->controller(['ai_provider' => 'ollama', 'ai_url' => 'http://localhost:11434'], $this->blockedClient())
            ->testConnection();
        $this->assertSame('error', $response->getData()['status']);
        $this->assertStringContainsString('allow_local_remote_servers', $response->getData()['message']);
    }

    public function testGeminiConnectionUsesHeaderAndIClient(): void {
        $response = $this->controller(['ai_provider' => 'gemini', 'api_key' => TestHelpers::GEMINI_KEY], $this->okClient())
            ->testConnection();
        $this->assertSame('success', $response->getData()['status']);
        $this->assertCount(1, $this->calls);
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $this->calls[0]['uri']);
        $this->assertSame(TestHelpers::GEMINI_KEY, array_change_key_case($this->calls[0]['options']['headers'])['x-goog-api-key']);
    }

    public function testHealthcheckConnectivityUsesIClientAndExplainsLocalBlock(): void {
        $data = $this->controller(['ai_provider' => 'ollama', 'ai_url' => 'http://localhost:11434'], $this->blockedClient())
            ->healthcheck()->getData();
        $conn = array_values(array_filter($data['checks'], fn ($c) => str_starts_with($c['name'], 'Conectividade')));
        $this->assertCount(1, $conn);
        $this->assertFalse($conn[0]['ok']);
        $this->assertStringContainsString('allow_local_remote_servers', $conn[0]['detail']);
    }

    public function testNoRawCurlLeftInSettingsController(): void {
        $src = file_get_contents(__DIR__ . '/../../../lib/Controller/SettingsController.php');
        $this->assertDoesNotMatchRegularExpression('/\bcurl_(init|exec|close|setopt)\b/', $src);
    }
}
