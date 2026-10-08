<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;

/**
 * C2: the Google API key must never travel in the URL, and a provider error
 * must never carry the key into the exception that bubbles up (which feeds
 * the log, job.error and the HTTP response).
 */
class AudioServiceProviderErrorTest extends TestCase {
    private array $calls = [];

    private function service(IClient $client, RecordingLogger $logger, array $config = []): AudioService {
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('decrypt')->willReturnArgument(0);
        $config = TestHelpers::config($this, $config + [
            'ai_provider' => 'gemini',
            'ai_url' => 'https://generativelanguage.googleapis.com',
            'ai_model' => 'gemini-2.5-flash',
            'api_key' => TestHelpers::GEMINI_KEY,
            'max_jobs_per_user_per_day' => '0',
            'long_audio_split_threshold' => '0',
            'save_audio' => 'false',
        ]);
        return new AudioService(
            $clientService,
            $this->createMock(IRootFolder::class),
            $logger,
            $config,
            $crypto,
            $this->createMock(JobMapper::class),
            new SecretRedactor($config, $crypto),
            new \OCA\Audiolog\Service\MediaTools($tm = TestHelpers::tempManager($this, sys_get_temp_dir() . '/audiolog_tests_' . getmypid())),
            $tm,
            \OCA\Audiolog\Tests\Unit\Support\TestHelpers::l10n($this),
        );
    }

    private function failingClient(): IClient {
        $client = $this->createMock(IClient::class);
        $fail = function (string $uri, array $options = []) {
            $this->calls[] = ['uri' => $uri, 'options' => $options];
            // What Guzzle produces on a 4xx when the key is in the query, plus
            // a provider body that echoes the key back.
            throw new \RuntimeException(
                'Client error: `POST ' . $uri . '` resulted in a `400 Bad Request` response: '
                . '{"error":{"message":"API key not valid: ' . TestHelpers::GEMINI_KEY . '"}}'
            );
        };
        $client->method('post')->willReturnCallback($fail);
        $client->method('get')->willReturnCallback($fail);
        return $client;
    }

    public function testGeminiKeyGoesInHeaderNotInUrl(): void {
        $logger = new RecordingLogger();
        $svc = $this->service($this->failingClient(), $logger);
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $svc->processAudio($tmp, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
            $this->fail('expected provider error');
        } catch (\Throwable $e) {
        } finally {
            @unlink($tmp);
        }
        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('key=', $call['uri']);
            $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $call['uri']);
            $headers = array_change_key_case($call['options']['headers'] ?? []);
            $this->assertSame(TestHelpers::GEMINI_KEY, $headers['x-goog-api-key'] ?? null);
        }
    }

    public function testProviderErrorBubblesUpWithoutKey(): void {
        $logger = new RecordingLogger();
        $svc = $this->service($this->failingClient(), $logger);
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $svc->processAudio($tmp, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
            $this->fail('expected provider error');
        } catch (\Throwable $e) {
            for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $x->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $logger->dump());
    }

    public function testGoogleSttUsesHeaderAndMasksErrors(): void {
        $logger = new RecordingLogger();
        $svc = $this->service($this->failingClient(), $logger, [
            'use_google_stt_for_transcription' => 'true',
            'google_stt_api_key' => TestHelpers::STT_KEY,
        ]);
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $svc->processAudio($tmp, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
            $this->fail('expected provider error');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(TestHelpers::STT_KEY, $e->getMessage());
        } finally {
            @unlink($tmp);
        }
        foreach ($this->calls as $call) {
            $this->assertStringNotContainsString('key=', $call['uri']);
            $headers = array_change_key_case($call['options']['headers'] ?? []);
            $this->assertSame(TestHelpers::STT_KEY, $headers['x-goog-api-key'] ?? null);
        }
        $this->assertStringNotContainsString(TestHelpers::STT_KEY, $logger->dump());
    }
}
