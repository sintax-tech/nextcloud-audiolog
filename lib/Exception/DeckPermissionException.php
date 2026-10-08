<?php
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * The user may not write to the requested Deck board/stack (or the stack is not on that board).
 */
class DeckPermissionException extends \RuntimeException {
}
