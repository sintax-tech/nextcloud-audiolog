<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Exception\InvalidInputException;
use OCA\Audiolog\Service\MediaTools;
use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use PHPUnit\Framework\TestCase;

/**
 * N3: when ffmpeg cannot extract the audio, the user is told why — an
 * unsupported format (with the accepted list) is not "install ffmpeg".
 */
class AudioServiceExtractionErrorsTest extends TestCase {
    private function failWith(array $runResult): InvalidInputException {
        $fs = new FakeFs($this);
        $fs->addFile('Videos/clip.mp4', TestHelpers::wavBytes(), 'video/mp4');
        $real = new MediaTools($this->createMock(\OCP\ITempManager::class));
        $media = $this->createMock(MediaTools::class);
        $media->method('looksLikePlaylist')->willReturnCallback(fn ($h) => $real->looksLikePlaylist($h));
        $media->method('isMediaContent')->willReturnCallback(fn ($b) => $real->isMediaContent($b));
        $media->method('extractAudioCommand')->willReturn(['ffmpeg']);
        $media->method('acceptedFormatsLabel')->willReturn($real->acceptedFormatsLabel());
        $media->method('isUnavailable')->willReturnCallback(fn ($rc) => $real->isUnavailable($rc));
        $media->method('isUnsupportedInput')->willReturnCallback(fn ($e) => $real->isUnsupportedInput($e));
        $media->method('run')->willReturn($runResult);
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder(), 'media' => $media]);
        try {
            $svc->processNextcloudFile('alice', 'Videos/clip.mp4', '', ['transcricao']);
        } catch (InvalidInputException $e) {
            return $e;
        }
        $this->fail('expected InvalidInputException');
    }

    /** @dataProvider unsupported */
    public function testUnsupportedFormatListsAcceptedFormats(string $stderr): void {
        $e = $this->failWith([1, '', $stderr]);
        $this->assertSame(415, $e->getHttpStatus());
        $this->assertStringContainsString('não suportado', $e->getMessage());
        $this->assertStringContainsString('WebM', $e->getMessage());
        $this->assertStringNotContainsString('ffmpeg', strtolower($e->getMessage()));
    }

    public static function unsupported(): array {
        return [
            'not on whitelist' => ["[in#0] Format not on whitelist 'mov,mp4,...'\n"],
            'invalid data' => ["clip.mp4: Invalid data found when processing input\n"],
            'no audio stream' => ["Output file does not contain any stream\n"],
        ];
    }

    /** @dataProvider unavailable */
    public function testMissingFfmpegSaysSo(int $rc): void {
        $e = $this->failWith([$rc, '', '']);
        $this->assertSame(503, $e->getHttpStatus());
        $this->assertStringContainsString('ffmpeg', $e->getMessage());
    }

    public static function unavailable(): array {
        return ['cannot start' => [-1], 'exec failed' => [127]];
    }

    public function testTimeoutHasItsOwnMessage(): void {
        $e = $this->failWith([MediaTools::EXIT_TIMEOUT, '', '']);
        $this->assertSame(504, $e->getHttpStatus());
    }

    public function testOtherFailureIsGenericNotInstallFfmpeg(): void {
        $e = $this->failWith([1, '', "Conversion failed!\n"]);
        $this->assertSame(422, $e->getHttpStatus());
        $this->assertStringNotContainsString('instal', $e->getMessage());
    }

    public function testWhitelistAlsoCoversAc3AndW64(): void {
        $argv = (new MediaTools($this->createMock(\OCP\ITempManager::class)))->probeDurationCommand('/tmp/x');
        $formats = explode(',', $argv[array_search('-format_whitelist', $argv, true) + 1]);
        foreach (['ac3', 'eac3', 'w64'] as $f) {
            $this->assertContains($f, $formats);
        }
    }
}
