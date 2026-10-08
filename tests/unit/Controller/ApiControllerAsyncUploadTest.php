<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\BackgroundJob\ProcessAudioJob;
use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\Job;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\DeckIntegration;
use OCA\Audiolog\Service\PermissionService;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** M3: the async upload copy is private (0600) and removed even when the job fails. */
class ApiControllerAsyncUploadTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/audiolog_m3a_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void {
        TestHelpers::rmTree($this->dir);
    }

    public function testAsyncUploadIsPrivateAndCleanedOnFailure(): void {
        $upload = TestHelpers::tempFile(TestHelpers::wavBytes());
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $k === 'async' ? 'true' : $d);
        $request->method('getUploadedFile')->willReturn([
            'name' => 'a.wav', 'type' => 'audio/wav', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK, 'size' => filesize($upload),
        ]);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);
        $job = new Job();
        $job->setId(3);
        $mapper = $this->createMock(JobMapper::class);
        $mapper->method('insert')->willReturn($job);
        $mapper->method('find')->willReturn($job);
        $queued = null;
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(function ($class, $arg) use (&$queued) { $queued = $arg; });
        $crypto = $this->createMock(CryptoHelper::class);
        $cfg = TestHelpers::config($this, []);
        $redactor = new SecretRedactor($cfg, $crypto);

        $controller = new ApiController(
            $request, $this->createMock(AudioService::class), $session, new RecordingLogger(), $crypto,
            $mapper, $jobList, $permissions, $this->createMock(IGroupManager::class), $cfg,
            $redactor, $this->createMock(DeckIntegration::class), TestHelpers::tempManager($this, $this->dir),
        );
        $response = $controller->process();
        @unlink($upload);

        $this->assertSame(202, $response->getStatus());
        $stable = $queued['tmpPath'];
        $this->assertStringStartsWith($this->dir, $stable);
        $this->assertFileExists($stable);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($stable)), -4));

        $audio = $this->createMock(AudioService::class);
        $audio->method('processAudio')->willThrowException(new \RuntimeException('provider down'));
        $bg = new ProcessAudioJob($this->createMock(ITimeFactory::class), $audio, $mapper, new RecordingLogger(), $redactor);
        (new \ReflectionMethod($bg, 'run'))->invoke($bg, $queued);

        $this->assertSame('failed', $job->getStatus());
        $this->assertFileDoesNotExist($stable);
    }
}
