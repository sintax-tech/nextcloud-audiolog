<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Exception;

/**
 * Failure talking to an AI/STT provider. The message is already redacted by
 * SecretRedactor and the original exception is deliberately NOT chained, so
 * nothing downstream (log serializer, job.error, HTTP) can reach the raw
 * message that may carry an API key.
 */
class ProviderException extends \RuntimeException {
}
