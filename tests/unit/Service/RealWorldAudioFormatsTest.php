<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\DeckIntegration;
use OCA\Audiolog\Service\MediaTools;
use OCA\Audiolog\Service\PermissionService;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the formats users actually send, checked end to end against
 * the content checks (finfo) and the ffmpeg demuxer whitelist:
 *  - WhatsApp voice notes: .opus = Opus in an Ogg container;
 *  - iPhone voice memos: .m4a = AAC in MP4;
 *  - the browser's MediaRecorder: .webm with Opus.
 * Fixtures in tests/fixtures/ (1 s sine, a few KB), made with
 * `ffmpeg -f lavfi -i sine=duration=1 -c:a libopus|aac ...`.
 */
class RealWorldAudioFormatsTest extends TestCase {
    private array $tmp = [];
    private ?string $sentMime = null;

    protected function tearDown(): void {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    /** [fixture, type the browser sends on upload, mimetype Nextcloud stores] */
    public static function formats(): array {
        return [
            'WhatsApp .opus' => ['whatsapp.opus', 'audio/ogg', 'audio/ogg'],
            'iPhone .m4a' => ['iphone.m4a', 'audio/x-m4a', 'audio/mp4'],
            // Nextcloud maps .webm to video/webm: that file goes through the
            // ffmpeg audio extraction (with the demuxer whitelist).
            'MediaRecorder .webm' => ['browser.webm', 'audio/webm;codecs=opus', 'video/webm'],
        ];
    }

    private function fixture(string $name): string {
        return (string)file_get_contents(__DIR__ . '/../../fixtures/' . $name);
    }

    /** @dataProvider formats */
    public function testUploadIsAccepted(string $fixture, string $clientType): void {
        $path = TestHelpers::tempFile($this->fixture($fixture), '.' . pathinfo($fixture, PATHINFO_EXTENSION));
        $this->tmp[] = $path;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $d);
        $request->method('getUploadedFile')->willReturn([
            'name' => $fixture, 'type' => $clientType, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path),
        ]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->once())->method('processAudio')
            ->with($path, $fixture, $clientType)
            ->willReturn(['success' => true]);
        $crypto = $this->createMock(CryptoHelper::class);
        $cfg = TestHelpers::config($this, []);

        $response = (new ApiController(
            $request, $audio, $session, new RecordingLogger(), $crypto,
            $this->createMock(JobMapper::class), $this->createMock(IJobList::class),
            $permissions, $this->createMock(IGroupManager::class), $cfg,
            new SecretRedactor($cfg, $crypto), $this->createMock(DeckIntegration::class),
            $this->createMock(\OCP\ITempManager::class), TestHelpers::l10n($this),
        ))->process();

        $this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
    }

    /** @dataProvider formats */
    public function testNextcloudFileIsAccepted(string $fixture, string $clientType, string $ncMime): void {
        if (str_starts_with($ncMime, 'video/') && trim((string)shell_exec('command -v ffmpeg 2>/dev/null')) === '') {
            $this->markTestSkipped('ffmpeg not installed (needed to extract audio from ' . $ncMime . ')');
        }
        $fs = new FakeFs($this);
        $fs->addFile('Audios/' . $fixture, $this->fixture($fixture), $ncMime);
        $sent = 0;
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]));
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(function ($uri, $opts) use (&$sent, $response) {
            $sent++;
            $this->sentMime = $opts['json']['contents'][0]['parts'][1]['inline_data']['mime_type'] ?? null;
            return $response;
        });
        $cs = $this->createMock(IClientService::class);
        $cs->method('newClient')->willReturn($client);
        $svc = TestHelpers::audioService($this, [
            'rootFolder' => $fs->rootFolder(),
            'clientService' => $cs,
            'config' => TestHelpers::config($this, [
                'ai_provider' => 'gemini', 'api_key' => 'k', 'max_jobs_per_user_per_day' => '0', 'long_audio_split_threshold' => '0',
            ]),
        ]);

        $result = $svc->processNextcloudFile('alice', 'Audios/' . $fixture, '', ['transcricao']);

        $this->assertSame('ok', $result['text']);
        $this->assertSame(1, $sent);
        if (str_starts_with($ncMime, 'video/')) {
            // Extracted by ffmpeg to mp3 before reaching the provider.
            $this->assertSame('audio/mpeg', $this->sentMime);
        }
    }

    /** @dataProvider formats */
    public function testFfprobeWithWhitelistsOpensTheFile(string $fixture): void {
        $ffprobe = trim((string)shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '') {
            $this->markTestSkipped('ffprobe not installed');
        }
        $dir = sys_get_temp_dir() . '/audiolog_fmt_' . getmypid();
        $tools = new MediaTools(TestHelpers::tempManager($this, $dir));
        [$rc, $out, $err] = $tools->run($tools->probeDurationCommand(__DIR__ . '/../../fixtures/' . $fixture), 30);

        $this->assertSame(0, $rc, $err);
        $this->assertEqualsWithDelta(1.0, (float)trim($out), 0.2);
    }
}
