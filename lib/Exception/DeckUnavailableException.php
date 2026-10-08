<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * The Deck app is not enabled for the user, or its services could not be resolved.
 */
class DeckUnavailableException extends \RuntimeException {
}
