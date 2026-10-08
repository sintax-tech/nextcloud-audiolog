<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * Input the user can fix (file too large, not audio...). The message is meant
 * for the user and the code is the HTTP status the controller answers with.
 */
class InvalidInputException extends \RuntimeException {
    public function __construct(string $message, private int $httpStatus = 400) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int {
        return $this->httpStatus;
    }
}
