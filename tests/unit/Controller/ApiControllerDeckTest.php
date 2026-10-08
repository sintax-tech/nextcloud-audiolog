<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ApiControllerDeckTest extends TestCase {
    private function controller(array $params, DeckIntegration $deck, ?AudioService $audio = null): ApiController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn ($k, $d = null) => $params[$k] ?? $d);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('mallory');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userHasAccess')->willReturn(true);
        $crypto = $this->createMock(CryptoHelper::class);
        $config = TestHelpers::config($this, []);
        return new ApiController(
            $request, $audio ?? $this->createMock(AudioService::class), $session, new RecordingLogger(), $crypto,
            $this->createMock(JobMapper::class), $this->createMock(IJobList::class),
            $permissions, $this->createMock(IGroupManager::class), $config,
            new SecretRedactor($config, $crypto), $deck,
            $this->createMock(\OCP\ITempManager::class),
            \OCA\Audiolog\Tests\Unit\Support\TestHelpers::l10n($this),
        );
    }

    public function testUserWithoutBoardPermissionGets403(): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->method('createCards')->willThrowException(new DeckPermissionException('no edit'));
        $audio = $this->createMock(AudioService::class);
        $audio->expects($this->never())->method('saveTasksToFile');

        $response = $this->controller(
            ['tasks' => [['title' => 'Injected']], 'boardId' => '3', 'stackId' => '5'],
            $deck, $audio
        )->createDeckCards();

        $this->assertSame(403, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    public function testCardsAreCreatedForTheSessionUser(): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->expects($this->once())->method('createCards')
            ->with('mallory', [['title' => 'Ok task']], 3, 5)->willReturn(1);

        $response = $this->controller(
            ['tasks' => [['title' => 'Ok task']], 'boardId' => '3', 'stackId' => '5'],
            $deck
        )->createDeckCards();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $response->getData()['count']);
    }

    /** R7: an archived board gets a clear message, not "not found". */
    public function testArchivedBoardMessage(): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->method('createCards')->willThrowException(new DeckPermissionException('x', DeckPermissionException::REASON_ARCHIVED));
        $response = $this->controller(['tasks' => [['title' => 'T task']], 'boardId' => '3', 'stackId' => '5'], $deck)->createDeckCards();
        $this->assertSame(409, $response->getStatus());
        $this->assertStringContainsString('arquivado', $response->getData()['error']);
    }

    public function testMissingBoardIs404(): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->method('createCards')->willThrowException(new DeckPermissionException('x', DeckPermissionException::REASON_NOT_FOUND));
        $response = $this->controller(['tasks' => [['title' => 'T task']], 'boardId' => '3', 'stackId' => '5'], $deck)->createDeckCards();
        $this->assertSame(404, $response->getStatus());
    }

    /** R8: a partial batch tells the user which cards exist, so they do not retry and duplicate. */
    public function testPartialBatchReportsCreatedCards(): void {
        $deck = $this->createMock(DeckIntegration::class);
        $deck->method('createCards')->willThrowException(new DeckPartialException(['One', 'Two'], 4));
        $response = $this->controller(['tasks' => [['title' => 'One']], 'boardId' => '3', 'stackId' => '5'], $deck)->createDeckCards();
        $data = $response->getData();
        $this->assertSame(207, $response->getStatus());
        $this->assertTrue($data['partial']);
        $this->assertSame(2, $data['count']);
        $this->assertSame(['One', 'Two'], $data['created']);
        $this->assertStringContainsString('One', $data['error']);
        $this->assertStringContainsString('2', $data['error']);
    }
}
