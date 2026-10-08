<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * R10: every log line on a provider path passes through the redactor (not
 * only the exception built by providerRequest). R9: the long-running STT
 * operation is polled on the same API version that created it.
 */
class AudioServiceProviderLogTest extends TestCase {
    private function markingRedactor(): SecretRedactor {
        $r = $this->createMock(SecretRedactor::class);
        $r->method('redact')->willReturnCallback(fn (string $t) => '[R]' . $t);
        return $r;
    }

    private function response(array $json): IResponse {
        $resp = $this->createMock(IResponse::class);
        $resp->method('getBody')->willReturn(json_encode($json));
        $resp->method('getHeaders')->willReturn([]);
        return $resp;
    }

    private function service(IClient $client, RecordingLogger $logger, array $config) {
        $cs = $this->createMock(IClientService::class);
        $cs->method('newClient')->willReturn($client);
        return TestHelpers::audioService($this, [
            'clientService' => $cs,
            'logger' => $logger,
            'redactor' => $this->markingRedactor(),
            'config' => TestHelpers::config($this, $config + [
                'ai_provider' => 'gemini', 'api_key' => 'k', 'max_jobs_per_user_per_day' => '0',
                'long_audio_split_threshold' => '0', 'save_audio' => 'false',
            ]),
        ]);
    }

    public function testFilesApiFallbackLogIsRedacted(): void {
        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \RuntimeException('upstream said no'));
        $logger = new RecordingLogger();
        $svc = $this->service($client, $logger, ['gemini_files_api_force' => 'true']);
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $svc->processAudio($tmp, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
        } catch (\Throwable $e) {
        } finally {
            @unlink($tmp);
        }
        $this->assertMatchesRegularExpression('/falling back to inline_data: \[R\]\[R\]upstream said no/', $logger->dump());
    }

    public function testSttSyncFallbackLogIsRedactedAndOperationPolledOnV1p1beta1(): void {
        $gets = [];
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(function (string $uri) {
            if (str_contains($uri, 'speech:recognize')) {
                throw new \RuntimeException('sync refused');
            }
            return $this->response(['name' => '123456789']);
        });
        $client->method('get')->willReturnCallback(function (string $uri) use (&$gets) {
            $gets[] = $uri;
            return $this->response(['done' => true, 'response' => ['results' => [['alternatives' => [['transcript' => 'olá']]]]]]);
        });
        $logger = new RecordingLogger();
        $svc = $this->service($client, $logger, ['use_google_stt_for_transcription' => 'true']);
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $result = $svc->processAudio($tmp, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
        } finally {
            @unlink($tmp);
        }

        $this->assertSame('olá', $result['text']);
        $this->assertMatchesRegularExpression('/falling back to longrunning: \[R\]\[R\]sync refused/', $logger->dump());
        $this->assertSame(['https://speech.googleapis.com/v1p1beta1/operations/123456789'], $gets);
    }
}
