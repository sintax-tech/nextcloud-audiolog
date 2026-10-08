<?php
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * The cards cannot be created on the requested Deck board/list. The reason
 * lets the controller show a precise (translated) message. A board or list
 * the user cannot read answers REASON_NOT_FOUND, like one that does not
 * exist, so ids of other users' boards do not leak.
 */
class DeckPermissionException extends \RuntimeException {
    public const REASON_NO_PERMISSION = 'no_permission';
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_ARCHIVED = 'archived';

    public function __construct(string $message, private string $reason = self::REASON_NO_PERMISSION) {
        parent::__construct($message);
    }

    public function getReason(): string {
        return $this->reason;
    }
}
