<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
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
 * C2: when the provider fails, neither the HTTP response nor the log may
 * carry the API key.
 */
class ApiControllerProviderErrorTest extends TestCase {
    public function testProviderErrorNeverReachesResponseOrLogWithKey(): void {
        $tmp = TestHelpers::tempFile(TestHelpers::wavBytes());
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $d);
        $request->method('getUploadedFile')->willReturn([
            'name' => 'a.wav', 'type' => 'audio/wav', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);

        $audio = $this->createMock(AudioService::class);
        $audio->method('processAudio')->willThrowException(new \RuntimeException(
            'Client error: `POST https://speech.googleapis.com/v1p1beta1/speech:recognize?key='
            . TestHelpers::GEMINI_KEY . '` resulted in a `403 Forbidden` response'
        ));

        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('decrypt')->willReturnArgument(0);
        $config = TestHelpers::config($this, ['api_key' => TestHelpers::GEMINI_KEY]);
        $logger = new RecordingLogger();

        $controller = new ApiController(
            $request, $audio, $session, $logger, $crypto,
            $this->createMock(JobMapper::class), $this->createMock(IJobList::class),
            $permissions, $this->createMock(IGroupManager::class), $config,
            new SecretRedactor($config, $crypto),
            $this->createMock(\OCA\Audiolog\Service\DeckIntegration::class),
            $this->createMock(\OCP\ITempManager::class),
        );
        $response = $controller->process();
        @unlink($tmp);

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, (string)json_encode($response->getData()));
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $logger->dump());
        $this->assertStringContainsString('key=***', $logger->dump());
    }
}
