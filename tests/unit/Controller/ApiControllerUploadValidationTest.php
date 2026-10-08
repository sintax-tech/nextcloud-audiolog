<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\DeckIntegration;
use OCA\Audiolog\Service\PermissionService;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * M1: max_file_size (MB, admin setting) is enforced and the MIME type is
 * detected from the bytes (finfo), not taken from the client.
 */
class ApiControllerUploadValidationTest extends TestCase {
    private array $tmp = [];

    protected function tearDown(): void {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function controller(string $content, string $clientType, array $config, AudioService $audio, string $suffix = '.wav'): ApiController {
        $path = TestHelpers::tempFile($content, $suffix);
        $this->tmp[] = $path;
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $d);
        $request->method('getUploadedFile')->willReturn([
            'name' => 'upload' . $suffix, 'type' => $clientType, 'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($content),
        ]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);
        $crypto = $this->createMock(CryptoHelper::class);
        $cfg = TestHelpers::config($this, $config);
        return new ApiController(
            $request, $audio, $session, new RecordingLogger(), $crypto,
            $this->createMock(JobMapper::class), $this->createMock(IJobList::class),
            $permissions, $this->createMock(IGroupManager::class), $cfg,
            new SecretRedactor($cfg, $crypto), $this->createMock(DeckIntegration::class),
            $this->createMock(\OCP\ITempManager::class),
        );
    }

    public function testUploadAboveMaxFileSizeIsRejectedWith413(): void {
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->never())->method('processAudio');
        $big = TestHelpers::wavBytes(700 * 1024); // ~1.4 MB
        $response = $this->controller($big, 'audio/wav', ['max_file_size' => '1'], $audio)->process();
        $this->assertSame(413, $response->getStatus());
    }

    public function testNonAudioContentIsRejectedEvenWithAudioClientType(): void {
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->never())->method('processAudio');
        $response = $this->controller("<?php echo 'hi';\n", 'audio/mpeg', [], $audio, '.mp3')->process();
        $this->assertSame(415, $response->getStatus());
    }

    public function testSaveRecordingIsValidatedToo(): void {
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->never())->method('saveRecording');
        $response = $this->controller("plain text, not audio\n", 'audio/webm', [], $audio, '.webm')->saveRecording();
        $this->assertSame(415, $response->getStatus());
    }

    public function testValidAudioWithinLimitIsProcessed(): void {
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->once())->method('processAudio')
            ->with($this->anything(), 'upload.wav', 'audio/wav')
            ->willReturn(['success' => true]);
        $response = $this->controller(TestHelpers::wavBytes(), 'audio/wav', ['max_file_size' => '100'], $audio)->process();
        $this->assertSame(200, $response->getStatus());
    }

    public function testClientTypeIsReplacedWhenItIsNotAudioOrVideo(): void {
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->once())->method('processAudio')
            ->with($this->anything(), $this->anything(), $this->stringStartsWith('audio/'))
            ->willReturn(['success' => true]);
        $this->controller(TestHelpers::wavBytes(), 'application/octet-stream', [], $audio)->process();
    }
}
