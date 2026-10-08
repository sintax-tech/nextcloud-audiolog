<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCA\Audiolog\Exception\DeckPermissionException;
use OCA\Audiolog\Exception\DeckUnavailableException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Talks to the Deck app ONLY through Deck's own services, resolved at runtime
 * from the container (Deck is an optional dependency, so nothing here is a
 * hard `use`). No SQL on Deck's tables: Deck's services apply its ACL
 * (user, group and circle shares), activity, notifications and ordering.
 *
 * Compatibility, checked against Deck 1.12 → 1.19 (Nextcloud 28 → 35):
 *  - CardService::create($title, $stackId, $type, $order, $owner, $description, ...)
 *    keeps the same leading positional parameters; 1.17 added scalar types,
 *    1.18 appended $startdate/$color. We pass exactly the first six, typed.
 *  - StackService::find($stackId) / findAll($boardId) check READ themselves.
 *  - BoardService::findAll($since, $fullDetails, $includeArchived) is stable.
 *  - PermissionService::getPermissions($boardId, $userId) is stable (1.17
 *    appended $allowDeleted) and returns [Acl::PERMISSION_* => bool].
 */
class DeckIntegration {
    private const CARD_SERVICE = 'OCA\Deck\Service\CardService';
    private const STACK_SERVICE = 'OCA\Deck\Service\StackService';
    private const BOARD_SERVICE = 'OCA\Deck\Service\BoardService';
    private const PERMISSION_SERVICE = 'OCA\Deck\Service\PermissionService';
    private const NO_PERMISSION_EXCEPTION = 'OCA\Deck\NoPermissionException';
    /** Acl::PERMISSION_EDIT in every Deck version since 0.x. */
    private const PERMISSION_EDIT = 1;
    /** Same default order Deck's own CardApiController uses. */
    private const CARD_ORDER = 999;
    private const MAX_TITLE = 255;

    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser('deck')
            && class_exists(self::CARD_SERVICE)
            && class_exists(self::STACK_SERVICE)
            && class_exists(self::BOARD_SERVICE)
            && class_exists(self::PERMISSION_SERVICE);
    }

    /**
     * Boards the user can add cards to (EDIT permission), not archived, not
     * deleted, with their live stacks.
     *
     * @return list<array{id:int,title:string,stacks:list<array{id:int,title:string}>}>
     */
    public function listBoards(string $userId): array {
        $this->assertAvailable();
        $boardService = $this->service(self::BOARD_SERVICE);
        $stackService = $this->service(self::STACK_SERVICE);

        $out = [];
        foreach ($boardService->findAll(-1, false, false) as $board) {
            $boardId = (int)$board->getId();
            if ((int)$board->getDeletedAt() > 0 || $board->getArchived()) {
                continue;
            }
            if (!$this->canEdit($boardId, $userId)) {
                continue;
            }
            $stacks = [];
            foreach ($stackService->findAll($boardId) as $stack) {
                if ((int)$stack->getDeletedAt() > 0) {
                    continue;
                }
                $stacks[] = ['id' => (int)$stack->getId(), 'title' => (string)$stack->getTitle()];
            }
            $out[] = ['id' => $boardId, 'title' => (string)$board->getTitle(), 'stacks' => $stacks];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['title'], $b['title']));
        return $out;
    }

    /**
     * Create one card per task in $stackId, after checking that the stack
     * belongs to $boardId and that $userId may edit that board.
     *
     * @param array<int,array{title?:string,assignee?:string,dueDate?:string}> $tasks
     * @return int number of cards created
     * @throws DeckPermissionException|DeckUnavailableException
     */
    public function createCards(string $userId, array $tasks, int $boardId, int $stackId): int {
        $this->assertAvailable();
        $stackService = $this->service(self::STACK_SERVICE);
        $cardService = $this->service(self::CARD_SERVICE);

        try {
            $stack = $stackService->find($stackId);
        } catch (\Throwable $e) {
            throw $this->translate($e);
        }
        if ($stack === null || (int)$stack->getBoardId() !== $boardId) {
            throw new DeckPermissionException('A lista informada não pertence ao quadro.');
        }
        if (!$this->canEdit($boardId, $userId)) {
            throw new DeckPermissionException('Sem permissão de edição neste quadro do Deck.');
        }

        $created = 0;
        foreach ($tasks as $task) {
            $title = trim((string)($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $description = '';
            if (!empty($task['assignee'])) {
                $description .= 'Responsável: ' . $task['assignee'] . "\n";
            }
            if (!empty($task['dueDate'])) {
                $description .= 'Prazo: ' . $task['dueDate'];
            }
            try {
                $cardService->create(
                    mb_substr($title, 0, self::MAX_TITLE),
                    $stackId,
                    'plain',
                    self::CARD_ORDER,
                    $userId,
                    trim($description)
                );
            } catch (\Throwable $e) {
                throw $this->translate($e);
            }
            $created++;
        }
        return $created;
    }

    private function canEdit(int $boardId, string $userId): bool {
        try {
            $permissions = $this->service(self::PERMISSION_SERVICE)->getPermissions($boardId, $userId);
        } catch (\Throwable $e) {
            $this->logger->debug('Audiolog: Deck permission lookup failed: ' . $e->getMessage());
            return false;
        }
        return is_array($permissions) && !empty($permissions[self::PERMISSION_EDIT]);
    }

    private function assertAvailable(): void {
        if (!$this->isAvailable()) {
            throw new DeckUnavailableException('Deck não está instalado ou habilitado.');
        }
    }

    private function service(string $class): object {
        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->warning('Audiolog: could not resolve Deck service ' . $class . ': ' . $e->getMessage());
            throw new DeckUnavailableException('Deck não está disponível.');
        }
    }

    private function translate(\Throwable $e): \RuntimeException {
        if (is_a($e, self::NO_PERMISSION_EXCEPTION)) {
            return new DeckPermissionException('Sem permissão neste quadro do Deck.');
        }
        if ($e instanceof DeckPermissionException || $e instanceof DeckUnavailableException) {
            return $e;
        }
        $this->logger->warning('Audiolog: Deck call failed: ' . $e->getMessage());
        // Deck's NotFound/BadRequest for an id the user can't see: same answer
        // as a permission refusal, so ids of other users' stacks don't leak.
        return new DeckPermissionException('Quadro ou lista do Deck não encontrados.');
    }
}
