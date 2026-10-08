<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Support;

use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TestHelpers {
    /** A Google-style API key (AIza + 35 chars) used as the secret under test. */
    public const GEMINI_KEY = 'AIzaSyD3adB33fC0ffee1234567890abcdefXYZ';
    public const STT_KEY = 'AIzaSyST7k3y0000000000000000000000000AB';

    /**
     * IConfig mock that answers getAppValue from $values and falls back to
     * the caller's default.
     *
     * @param array<string,string> $values
     */
    public static function config(TestCase $tc, array $values): IConfig&MockObject {
        $config = (new \ReflectionMethod($tc, 'createMock'))->invoke($tc, IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $values[$key] ?? $default
        );
        $config->method('getSystemValue')->willReturnCallback(fn ($key, $default = '') => $default);
        return $config;
    }

    /** Smallest valid 16-bit mono PCM WAV (finfo reports audio/x-wav). */
    public static function wavBytes(int $samples = 800): string {
        $data = str_repeat("\0\0", $samples);
        return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;
    }

    public static function tempFile(string $content, string $suffix = '.wav'): string {
        $path = tempnam(sys_get_temp_dir(), 'audiolog_test_');
        $final = $path . $suffix;
        rename($path, $final);
        file_put_contents($final, $content);
        return $final;
    }
}
