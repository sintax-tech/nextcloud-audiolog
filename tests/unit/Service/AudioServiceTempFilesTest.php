<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;

/**
 * M3: meeting audio must not stay in the temp dir when the provider fails.
 * Temporary files come from ITempManager (0600, cleaned by Nextcloud) and are
 * removed in `finally` blocks.
 */
class AudioServiceTempFilesTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/audiolog_m3_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        TestHelpers::rmTree($this->dir);
    }

    private function failingService(FakeFs $fs, array $config = []) {
        $client = $this->createMock(IClient::class);
        $client->method('post')->willThrowException(new \RuntimeException('provider down'));
        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);
        return TestHelpers::audioService($this, [
            'rootFolder' => $fs->rootFolder(),
            'clientService' => $clientService,
            'tempManager' => TestHelpers::tempManager($this, $this->dir),
            'config' => TestHelpers::config($this, $config + [
                'ai_provider' => 'gemini', 'api_key' => 'k', 'max_jobs_per_user_per_day' => '0',
                'long_audio_split_threshold' => '25', 'save_audio' => 'false',
            ]),
        ]);
    }

    public function testNoTempFileLeftWhenProviderFailsOnUpload(): void {
        $svc = $this->failingService(new FakeFs($this));
        $upload = TestHelpers::tempFile(TestHelpers::wavBytes());
        try {
            $svc->processAudio($upload, 'a.wav', 'audio/wav', '', ['transcricao'], 'alice');
            $this->fail('provider error expected');
        } catch (\RuntimeException $e) {
        } finally {
            @unlink($upload);
        }
        $this->assertSame([], TestHelpers::leftovers($this->dir));
    }

    public function testNoTempFileLeftWhenProviderFailsOnNextcloudVideo(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Videos/meeting.mp4', TestHelpers::wavBytes(), 'video/mp4');
        $svc = $this->failingService($fs);
        try {
            $svc->processNextcloudFile('alice', 'Videos/meeting.mp4', '', ['transcricao']);
            $this->fail('error expected');
        } catch (\RuntimeException $e) {
        }
        $this->assertSame([], TestHelpers::leftovers($this->dir));
    }

    public function testDocxExportLeavesNoTempFile(): void {
        $fs = new FakeFs($this);
        $svc = $this->failingService($fs);
        $result = $svc->saveToDocx('alice', 'Ata', "# Ata\n\n- item", true);
        $this->assertNotEmpty($result['downloadData']);
        $this->assertSame([], TestHelpers::leftovers($this->dir));
    }

    public function testNoSystemTempUsedDirectly(): void {
        $src = file_get_contents(__DIR__ . '/../../../lib/Service/AudioService.php')
            . file_get_contents(__DIR__ . '/../../../lib/Controller/ApiController.php');
        $this->assertStringNotContainsString('sys_get_temp_dir()', $src);
        $this->assertStringNotContainsString('tempnam(', $src);
    }
}
