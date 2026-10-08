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
        $tempManager = $deps['tempManager'] ?? self::tempManager($tc, sys_get_temp_dir() . '/audiolog_tests_' . getmypid());
        return new AudioService(
            $deps['clientService'] ?? $mock(IClientService::class),
            $deps['rootFolder'] ?? $mock(IRootFolder::class),
            $deps['logger'] ?? new RecordingLogger(),
            $config,
            $crypto,
            $deps['jobMapper'] ?? $mock(JobMapper::class),
            $deps['redactor'] ?? new SecretRedactor($config, $crypto),
            $deps['media'] ?? new \OCA\Audiolog\Service\MediaTools($tempManager),
            $tempManager,
            $deps['l10n'] ?? self::l10n($tc),
        );
    }

    /**
     * IL10N mock that applies the placeholders like the real one (sprintf
     * style) and prefixes $mark, so a test can tell a string went through t().
     */
    public static function l10n(TestCase $tc, string $mark = ''): \OCP\IL10N {
        $l = (new \ReflectionMethod($tc, 'createMock'))->invoke($tc, \OCP\IL10N::class);
        $l->method('t')->willReturnCallback(
            fn (string $text, $parameters = []) => $mark . ($parameters === [] ? $text : vsprintf($text, (array)$parameters))
        );
        $l->method('getLanguageCode')->willReturn('pt_BR');
        return $l;
    }

    /**
     * ITempManager mock that creates real 0600 files/folders under $dir, like
     * OC\TempManager does, so tests can assert what is left behind.
     */
    public static function tempManager(TestCase $tc, string $dir): \OCP\ITempManager {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
            register_shutdown_function([self::class, 'rmTree'], $dir);
        }
        $tm = (new \ReflectionMethod($tc, 'createMock'))->invoke($tc, \OCP\ITempManager::class);
        $tm->method('getTemporaryFile')->willReturnCallback(function ($postFix = '') use ($dir) {
            $f = tempnam($dir, 'oc_tmp_');
            if ($postFix !== '') {
                // Same naming as OC\TempManager::buildFileNameWithSuffix: "<tmp>-.<ext>".
                $named = $f . '-.' . ltrim((string)$postFix, '.');
                rename($f, $named);
                $f = $named;
            }
            chmod($f, 0600);
            return $f;
        });
        $tm->method('getTemporaryFolder')->willReturnCallback(function ($postFix = '') use ($dir) {
            $f = tempnam($dir, 'oc_tmp_');
            unlink($f);
            mkdir($f, 0700);
            return $f . '/';
        });
        $tm->method('getTempBaseDir')->willReturn($dir);
        return $tm;
    }

    /** Recursively list what is left under $dir. */
    public static function leftovers(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $out[] = $f->getPathname();
        }
        return $out;
    }

    public static function rmTree(string $dir): void {
        foreach (self::leftovers($dir) as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
