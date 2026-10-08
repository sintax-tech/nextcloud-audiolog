<?php
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * A Deck call failed in the middle of a batch: the cards in getCreated()
 * already exist, so the user must not simply retry the whole batch.
 */
class DeckPartialException extends \RuntimeException {
    /** @param list<string> $created */
    public function __construct(private array $created, private int $total) {
        parent::__construct(count($created) . ' of ' . $total . ' Deck cards created before a failure');
    }

    /** @return list<string> titles of the cards that were created */
    public function getCreated(): array {
        return $this->created;
    }

    public function getTotal(): int {
        return $this->total;
    }
}
