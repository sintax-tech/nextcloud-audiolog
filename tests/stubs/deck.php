<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Minimal stand-ins for the Deck classes Audiolog talks to, so the tests can
 * mock them without a Deck checkout. Method names and positional parameters
 * follow Deck 1.12 → 1.19 (NC 28 → 35); parameters left untyped where the
 * versions differ (1.17 added scalar types, 1.18 appended startdate/color to
 * CardService::create, 1.17 added $allowDeleted to getPermissions).
 */
declare(strict_types=1);

namespace OCA\Deck {
    if (!class_exists(NoPermissionException::class)) {
        class NoPermissionException extends \Exception {
        }
    }
}

namespace OCA\Deck\Db {
    if (!class_exists(Acl::class)) {
        class Acl {
            public const PERMISSION_READ = 0;
            public const PERMISSION_EDIT = 1;
            public const PERMISSION_SHARE = 2;
            public const PERMISSION_MANAGE = 3;
        }
    }

    if (!class_exists(Board::class)) {
        class Board {
            public function __construct(
                private int $id = 0,
                private string $title = '',
                private bool $archived = false,
                private int $deletedAt = 0,
            ) {
            }
            public function getId() { return $this->id; }
            public function getTitle() { return $this->title; }
            public function getArchived() { return $this->archived; }
            public function getDeletedAt() { return $this->deletedAt; }
        }
    }

    if (!class_exists(Stack::class)) {
        class Stack {
            public function __construct(
                private int $id = 0,
                private string $title = '',
                private int $boardId = 0,
                private int $order = 0,
                private int $deletedAt = 0,
            ) {
            }
            public function getId() { return $this->id; }
            public function getTitle() { return $this->title; }
            public function getBoardId() { return $this->boardId; }
            public function getOrder() { return $this->order; }
            public function getDeletedAt() { return $this->deletedAt; }
        }
    }

    if (!class_exists(Card::class)) {
        class Card {
            public function getId() { return 0; }
        }
    }
}

namespace OCA\Deck\Service {
    if (!class_exists(BoardService::class)) {
        class BoardService {
            public function findAll(int $since = -1, bool $fullDetails = false, bool $includeArchived = true): array { return []; }
            public function find(int $boardId, bool $fullDetails = true, bool $allowDeleted = false) { return null; }
        }
    }

    if (!class_exists(StackService::class)) {
        class StackService {
            public function find($stackId) { return null; }
            public function findAll($boardId, $since = -1) { return []; }
        }
    }

    if (!class_exists(CardService::class)) {
        class CardService {
            public function create($title, $stackId, $type, $order, $owner, $description = '', $duedate = null) { return null; }
        }
    }

    if (!class_exists(PermissionService::class)) {
        class PermissionService {
            public function getPermissions($boardId, ?string $userId = null) { return []; }
        }
    }
}
