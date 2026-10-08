<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Controller;

use OCA\Audiolog\Controller\ApiController;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Exception\DeckPartialException;
use OCA\Audiolog\Exception\DeckPermissionException;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\DeckIntegration;
use OCA\Audiolog\Service\PermissionService;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * R5: every error the API returns to the browser goes through IL10N (the
 * mock prefixes "«" to whatever passes through t()).
 */
class ApiControllerL10nTest extends TestCase {
    private const MARK = '«';
    private array $tmp = [];

    protected function tearDown(): void {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function controller(array $params = [], ?array $upload = null, ?AudioService $audio = null, ?DeckIntegration $deck = null, bool $loggedIn = true): ApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
        $request->method('getUploadedFile')->willReturn($upload);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($loggedIn ? $user : null);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);
        $crypto = $this->createMock(CryptoHelper::class);
        $cfg = TestHelpers::config($this, ['max_file_size' => '1']);
        return new ApiController(
            $request, $audio ?? $this->createMock(AudioService::class), $session, new RecordingLogger(), $crypto,
            $this->createMock(JobMapper::class), $this->createMock(IJobList::class),
            $permissions, $this->createMock(IGroupManager::class), $cfg,
            new SecretRedactor($cfg, $crypto), $deck ?? $this->createMock(DeckIntegration::class),
            $this->createMock(\OCP\ITempManager::class), TestHelpers::l10n($this, self::MARK),
        );
    }

    private function upload(string $content): array {
        $path = TestHelpers::tempFile($content);
        $this->tmp[] = $path;
        return ['name' => 'a.wav', 'type' => 'audio/wav', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function assertTranslated(\OCP\AppFramework\Http\JSONResponse $r): void {
        $error = $r->getData()['error'] ?? null;
        $this->assertIsString($error, json_encode($r->getData()));
        $this->assertStringStartsWith(self::MARK, $error);
    }

    public function testControllerErrorsAreTranslated(): void {
        $this->assertTranslated($this->controller([], null, null, null, false)->getHistory());
        $this->assertTranslated($this->controller()->createDeckCards());
        $this->assertTranslated($this->controller(['path' => '../etc'])->renameRecording());
        $this->assertTranslated($this->controller([], $this->upload("not audio\n"))->process());
        $this->assertTranslated($this->controller([], $this->upload(TestHelpers::wavBytes(700 * 1024)))->process());
    }

    /** @dataProvider deckFailures */
    public function testDeckErrorsAreTranslated(\Throwable $e): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->method('createCards')->willThrowException($e);
        $this->assertTranslated($this->controller(['tasks' => [['title' => 'T task']], 'boardId' => '3', 'stackId' => '5'], null, null, $deck)->createDeckCards());
    }

    public static function deckFailures(): array {
        return [
            'no permission' => [new DeckPermissionException('x')],
            'not found' => [new DeckPermissionException('x', DeckPermissionException::REASON_NOT_FOUND)],
            'archived' => [new DeckPermissionException('x', DeckPermissionException::REASON_ARCHIVED)],
            'partial' => [new DeckPartialException(['One'], 3)],
            'unavailable' => [new \OCA\Audiolog\Exception\DeckUnavailableException('x')],
        ];
    }

    /** Service-built input errors (quota, size, type, not found) reach the browser translated. */
    public function testServiceInputErrorsAreTranslated(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Music/big.wav', str_repeat('a', 2 * 1024 * 1024), 'audio/wav');
        $mapper = $this->createMock(JobMapper::class);
        $mapper->method('countSince')->willReturn(99);
        $l10n = TestHelpers::l10n($this, self::MARK);

        $sizeSvc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder(), 'l10n' => $l10n,
            'config' => TestHelpers::config($this, ['max_file_size' => '1', 'max_jobs_per_user_per_day' => '0'])]);
        $quotaSvc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder(), 'l10n' => $l10n, 'jobMapper' => $mapper,
            'config' => TestHelpers::config($this, ['max_jobs_per_user_per_day' => '50'])]);

        $size = $this->controller(['ncPath' => 'Music/big.wav'], null, $sizeSvc)->process();
        $this->assertSame(413, $size->getStatus());
        $this->assertTranslated($size);

        $quota = $this->controller(['ncPath' => 'Music/big.wav'], null, $quotaSvc)->process();
        $this->assertSame(429, $quota->getStatus());
        $this->assertTranslated($quota);

        $missing = $this->controller(['ncPath' => 'Music/none.wav'], null, $sizeSvc)->process();
        $this->assertSame(404, $missing->getStatus());
        $this->assertTranslated($missing);

        $this->assertStringStartsWith(self::MARK, $sizeSvc->createFolder('alice', '', '///')['error']);
    }
}
