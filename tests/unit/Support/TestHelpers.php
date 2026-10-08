<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Support;

use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
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

    /**
     * AudioService with mocks for every collaborator; pass real objects or
     * configured mocks in $deps (keys: clientService, rootFolder, logger,
     * config, crypto, jobMapper, redactor) to override.
     */
    public static function audioService(TestCase $tc, array $deps = []): AudioService {
        $mock = fn (string $class) => (new \ReflectionMethod($tc, 'createMock'))->invoke($tc, $class);
        $crypto = $deps['crypto'] ?? (function () use ($mock) {
            $c = $mock(CryptoHelper::class);
            $c->method('decrypt')->willReturnArgument(0);
            return $c;
        })();
        $config = $deps['config'] ?? self::config($tc, ['max_jobs_per_user_per_day' => '0']);
        return new AudioService(
            $deps['clientService'] ?? $mock(IClientService::class),
            $deps['rootFolder'] ?? $mock(IRootFolder::class),
            $deps['logger'] ?? new RecordingLogger(),
            $config,
            $crypto,
            $deps['jobMapper'] ?? $mock(JobMapper::class),
            $deps['redactor'] ?? new SecretRedactor($config, $crypto),
        );
    }
}
