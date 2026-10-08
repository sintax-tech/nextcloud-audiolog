<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Support;

use Psr\Log\AbstractLogger;

/**
 * Logger that keeps every line (message + context) so a test can assert a
 * secret never reached the log.
 */
final class RecordingLogger extends AbstractLogger {
    /** @var list<array{level:string,message:string,context:array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
    }

    /** Everything that was logged, flattened to one string (exception messages included). */
    public function dump(): string {
        $out = '';
        foreach ($this->records as $r) {
            $out .= $r['level'] . ' ' . $r['message'] . "\n";
            foreach ($r['context'] as $v) {
                if ($v instanceof \Throwable) {
                    for ($e = $v; $e !== null; $e = $e->getPrevious()) {
                        $out .= get_class($e) . ': ' . $e->getMessage() . "\n";
                    }
                } elseif (is_scalar($v)) {
                    $out .= (string)$v . "\n";
                } else {
                    $out .= json_encode($v) . "\n";
                }
            }
        }
        return $out;
    }
}
