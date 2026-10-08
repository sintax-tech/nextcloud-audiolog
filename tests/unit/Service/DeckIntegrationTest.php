<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Exception\DeckPermissionException;
use OCA\Audiolog\Exception\DeckUnavailableException;
use OCA\Audiolog\Service\DeckIntegration;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService as DeckPermissionService;
use OCA\Deck\Service\StackService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * C1/M6: Deck is reached only through Deck's own services, and the user's
 * EDIT permission on the target board is checked before creating cards.
 */
class DeckIntegrationTest extends TestCase {
    private CardService&MockObject $cards;
    private StackService&MockObject $stacks;
    private BoardService&MockObject $boards;
    private DeckPermissionService&MockObject $perms;
    private IAppManager&MockObject $appManager;

    protected function setUp(): void {
        $this->cards = $this->createMock(CardService::class);
        $this->stacks = $this->createMock(StackService::class);
        $this->boards = $this->createMock(BoardService::class);
        $this->perms = $this->createMock(DeckPermissionService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager->method('isEnabledForUser')->with('deck')->willReturn(true);
    }

    private function integration(): DeckIntegration {
        $map = [
            CardService::class => $this->cards,
            StackService::class => $this->stacks,
            BoardService::class => $this->boards,
            DeckPermissionService::class => $this->perms,
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id) => $map[$id]);
        return new DeckIntegration($this->appManager, $container, new RecordingLogger());
    }

    private function permissions(bool $edit): array {
        return [Acl::PERMISSION_READ => true, Acl::PERMISSION_EDIT => $edit, Acl::PERMISSION_MANAGE => false, Acl::PERMISSION_SHARE => false];
    }

    public function testUserWithoutEditPermissionIsRefused(): void {
        $this->stacks->method('find')->with(5)->willReturn(new Stack(5, 'Todo', 3));
        $this->perms->method('getPermissions')->with(3, 'mallory')->willReturn($this->permissions(false));
        $this->cards->expects($this->never())->method('create');

        $this->expectException(DeckPermissionException::class);
        $this->integration()->createCards('mallory', [['title' => 'Inject card']], 3, 5);
    }

    public function testUserWithoutReadAccessToStackIsRefused(): void {
        // Deck's StackService::find checks READ itself and throws.
        $this->stacks->method('find')->willThrowException(new NoPermissionException('no'));
        $this->cards->expects($this->never())->method('create');

        $this->expectException(DeckPermissionException::class);
        $this->integration()->createCards('mallory', [['title' => 'Inject card']], 3, 5);
    }

    public function testStackFromAnotherBoardIsRefused(): void {
        // Client says board 3 (where it may edit) but sends a stack of board 9.
        $this->stacks->method('find')->with(5)->willReturn(new Stack(5, 'Todo', 9));
        $this->perms->method('getPermissions')->willReturn($this->permissions(true));
        $this->cards->expects($this->never())->method('create');

        $this->expectException(DeckPermissionException::class);
        $this->integration()->createCards('alice', [['title' => 'X task']], 3, 5);
    }

    public function testDeckRefusalDuringCreateIsReportedAsPermission(): void {
        $this->stacks->method('find')->willReturn(new Stack(5, 'Todo', 3));
        $this->perms->method('getPermissions')->willReturn($this->permissions(true));
        $this->cards->method('create')->willThrowException(new NoPermissionException('no'));

        $this->expectException(DeckPermissionException::class);
        $this->integration()->createCards('alice', [['title' => 'X task']], 3, 5);
    }

    public function testCreatesCardsThroughCardServiceWithPositionalArgs(): void {
        $this->stacks->method('find')->willReturn(new Stack(5, 'Todo', 3));
        $this->perms->method('getPermissions')->with(3, 'alice')->willReturn($this->permissions(true));
        $calls = [];
        $this->cards->expects($this->exactly(2))->method('create')->willReturnCallback(
            function (...$args) use (&$calls) { $calls[] = $args; return null; }
        );

        $count = $this->integration()->createCards('alice', [
            ['title' => 'Enviar relatório', 'assignee' => 'Ana', 'dueDate' => 'sexta'],
            ['title' => ''],
            ['title' => 'Revisar contrato'],
        ], 3, 5);

        $this->assertSame(2, $count);
        // Same 7 leading positional params from Deck 1.12 to 1.19.
        $this->assertSame(['Enviar relatório', 5, 'plain', 999, 'alice'], array_slice($calls[0], 0, 5));
        $this->assertStringContainsString('Ana', $calls[0][5]);
        $this->assertStringContainsString('sexta', $calls[0][5]);
        $this->assertSame('Revisar contrato', $calls[1][0]);
    }

    public function testUnavailableDeckIsRefusedWithoutFallingBackToSql(): void {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(false);
        $integration = new DeckIntegration($appManager, $this->createMock(ContainerInterface::class), new RecordingLogger());

        $this->assertFalse($integration->isAvailable());
        $this->expectException(DeckUnavailableException::class);
        $integration->createCards('alice', [['title' => 'X task']], 3, 5);
    }

    public function testListBoardsKeepsOnlyEditableLiveBoardsAndStacks(): void {
        $this->boards->method('findAll')->willReturn([
            new Board(1, 'Mine'),
            new Board(2, 'Group shared, read only'),
            new Board(3, 'Deleted', false, 1700000000),
            new Board(4, 'Archived', true),
            new Board(5, 'Group shared, editable'),
        ]);
        $this->perms->method('getPermissions')->willReturnCallback(
            fn ($boardId) => $this->permissions(in_array($boardId, [1, 3, 4, 5], true))
        );
        $this->stacks->method('findAll')->willReturnCallback(fn ($boardId) => [
            new Stack(10 + $boardId, 'Todo', $boardId),
            new Stack(20 + $boardId, 'Gone', $boardId, 1, 1700000000),
        ]);

        $boards = $this->integration()->listBoards('alice');

        // Sorted by title; read-only, deleted and archived boards are gone.
        $this->assertSame([5, 1], array_column($boards, 'id'));
        $this->assertSame([['id' => 11, 'title' => 'Todo']], $boards[1]['stacks']);
    }
}
