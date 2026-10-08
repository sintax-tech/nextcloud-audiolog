<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Service\MediaTools;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
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
        $tools = $this->tools();
        $this->assertWhitelistedBeforeInput($tools->probeDurationCommand('/tmp/a.mp3'));
        $this->assertWhitelistedBeforeInput($tools->sliceCommand('/tmp/a.mp3', 0.0, 10.0, '/tmp/b.mp3'));
        $this->assertWhitelistedBeforeInput($tools->extractAudioCommand('/tmp/v.mp4', '/tmp/a.mp3'));
    }

    public function testCommandsAreArgvArraysWithoutShell(): void {
        $argv = ($this->tools())->extractAudioCommand("/tmp/x'; rm -rf /.mp4", '/tmp/a.mp3');
        $this->assertContains("/tmp/x'; rm -rf /.mp4", $argv);
        $this->assertSame('ffmpeg', $argv[0]);
    }

    /** @dataProvider playlists */
    public function testPlaylistContentIsDetected(string $head): void {
        $this->assertTrue(($this->tools())->looksLikePlaylist($head));
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
        $this->assertFalse(($this->tools())->looksLikePlaylist("RIFF\x24\x00\x00\x00WAVEfmt "));
        $this->assertFalse(($this->tools())->looksLikePlaylist("\x1A\x45\xDF\xA3webm"));
    }

    private ?string $tmpDir = null;

    private function tools(): MediaTools {
        $this->tmpDir ??= sys_get_temp_dir() . '/audiolog_mt_' . bin2hex(random_bytes(4));
        return new MediaTools(TestHelpers::tempManager($this, $this->tmpDir));
    }

    protected function tearDown(): void {
        if ($this->tmpDir !== null) {
            TestHelpers::rmTree($this->tmpDir);
        }
    }

    /**
     * R1: a child that writes far more than a pipe buffer (64 KB) to stderr
     * must not deadlock run(): ffmpeg prints one error per corrupt frame.
     */
    public function testLargeStderrDoesNotDeadlock(): void {
        $start = microtime(true);
        [$rc, $out, $err] = $this->tools()->run([
            PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("frame error\n", 200000)); echo "done";',
        ], 20);
        $this->assertSame(0, $rc);
        $this->assertSame('done', $out);
        $this->assertNotSame('', $err);
        $this->assertLessThanOrEqual(65536, strlen($err), 'only the tail of stderr is kept');
        $this->assertLessThan(15, microtime(true) - $start);
        $this->assertSame([], TestHelpers::leftovers($this->tmpDir), 'stderr temp file removed');
    }

    public function testRunawayProcessIsKilledAtTimeout(): void {
        $start = microtime(true);
        [$rc] = $this->tools()->run([PHP_BINARY, '-r', 'sleep(30);'], 1);
        $this->assertSame(MediaTools::EXIT_TIMEOUT, $rc);
        $this->assertLessThan(10, microtime(true) - $start);
    }

    public function testFfmpegCommandsAreQuiet(): void {
        foreach ([
            $this->tools()->sliceCommand('/tmp/a.mp3', 0.0, 1.0, '/tmp/b.mp3'),
            $this->tools()->extractAudioCommand('/tmp/v.mp4', '/tmp/a.mp3'),
        ] as $argv) {
            $this->assertContains('-nostats', $argv);
            $this->assertSame('error', $argv[array_search('-loglevel', $argv, true) + 1]);
        }
    }

    /**
     * R2: only media demuxers may open the input; manifest/playlist demuxers
     * (dash, hls, concat, sdp...) could read other users' files via file://.
     */
    public function testEveryCommandRestrictsDemuxers(): void {
        foreach ([
            $this->tools()->probeDurationCommand('/tmp/a.mp3'),
            $this->tools()->sliceCommand('/tmp/a.mp3', 0.0, 10.0, '/tmp/b.mp3'),
            $this->tools()->extractAudioCommand('/tmp/v.mp4', '/tmp/a.mp3'),
        ] as $argv) {
            $fw = array_search('-format_whitelist', $argv, true);
            $this->assertNotFalse($fw, implode(' ', $argv));
            $this->assertLessThan(array_search('-i', $argv, true), $fw);
            $formats = explode(',', $argv[$fw + 1]);
            foreach (['mov', 'matroska', 'webm', 'mp3', 'ogg', 'wav', 'flac', 'aac'] as $ok) {
                $this->assertContains($ok, $formats);
            }
            foreach (['dash', 'webm_dash_manifest', 'hls', 'concat', 'sdp', 'image2', 'lavfi'] as $bad) {
                $this->assertNotContains($bad, $formats);
            }
        }
    }

    /** @dataProvider manifests */
    public function testXmlManifestsAreDetected(string $head): void {
        $this->assertTrue($this->tools()->looksLikePlaylist($head));
    }

    public static function manifests(): array {
        return [
            'dash with prolog' => ["<?xml version=\"1.0\"?>\n<MPD xmlns=\"urn:mpeg:dash:schema:mpd:2011\">"],
            'dash bare' => ["  <MPD type=\"static\">"],
            'smooth streaming' => ["<SmoothStreamingMedia MajorVersion=\"2\">"],
        ];
    }

    public function testMediaContentIsSniffedFromBytes(): void {
        $this->assertTrue($this->tools()->isMediaContent(TestHelpers::wavBytes()));
        $this->assertFalse($this->tools()->isMediaContent("<?xml version=\"1.0\"?><MPD></MPD>"));
        $this->assertFalse($this->tools()->isMediaContent("just some text\n"));
    }
}
