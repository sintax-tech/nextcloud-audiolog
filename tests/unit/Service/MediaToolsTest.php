<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\MediaTools;
use PHPUnit\Framework\TestCase;

/**
 * M2: ffmpeg/ffprobe only read local files/pipes (no http, hls sub-requests,
 * etc.), and playlist content is refused before it reaches them.
 */
class MediaToolsTest extends TestCase {
    private function assertWhitelistedBeforeInput(array $argv): void {
        $wl = array_search('-protocol_whitelist', $argv, true);
        $this->assertNotFalse($wl, 'missing -protocol_whitelist in ' . implode(' ', $argv));
        $this->assertSame('file,pipe', $argv[$wl + 1]);
        $input = array_search('-i', $argv, true);
        if ($input !== false) {
            $this->assertLessThan($input, $wl, 'whitelist must come before -i');
        }
    }

    public function testEveryCommandRestrictsProtocols(): void {
        $tools = new MediaTools();
        $this->assertWhitelistedBeforeInput($tools->probeDurationCommand('/tmp/a.mp3'));
        $this->assertWhitelistedBeforeInput($tools->sliceCommand('/tmp/a.mp3', 0.0, 10.0, '/tmp/b.mp3'));
        $this->assertWhitelistedBeforeInput($tools->extractAudioCommand('/tmp/v.mp4', '/tmp/a.mp3'));
    }

    public function testCommandsAreArgvArraysWithoutShell(): void {
        $argv = (new MediaTools())->extractAudioCommand("/tmp/x'; rm -rf /.mp4", '/tmp/a.mp3');
        $this->assertContains("/tmp/x'; rm -rf /.mp4", $argv);
        $this->assertSame('ffmpeg', $argv[0]);
    }

    /** @dataProvider playlists */
    public function testPlaylistContentIsDetected(string $head): void {
        $this->assertTrue((new MediaTools())->looksLikePlaylist($head));
    }

    public static function playlists(): array {
        return [
            'hls' => ["#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\nhttp://169.254.169.254/latest\n"],
            'bom + spaces' => ["\xEF\xBB\xBF  \n#EXTM3U\n"],
            'lowercase' => ["#extm3u\n"],
            'ffconcat' => ["ffconcat version 1.0\nfile http://10.0.0.1/x\n"],
        ];
    }

    public function testRealMediaIsNotAPlaylist(): void {
        $this->assertFalse((new MediaTools())->looksLikePlaylist("RIFF\x24\x00\x00\x00WAVEfmt "));
        $this->assertFalse((new MediaTools())->looksLikePlaylist("\x1A\x45\xDF\xA3webm"));
    }
}
