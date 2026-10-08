<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

use OCP\ITempManager;

/**
 * ffmpeg/ffprobe invocations, always as argv arrays through proc_open (no
 * shell) and always with, before the input:
 *  - `-protocol_whitelist file,pipe`: a playlist (HLS/concat) disguised as
 *    audio/video would otherwise make the demuxer fetch http(s) URLs from
 *    the server (blind SSRF into the internal network);
 *  - `-format_whitelist` with media demuxers only: manifest demuxers (DASH,
 *    HLS, concat, SDP...) can still open `file://` paths, i.e. other users'
 *    recordings in the data directory.
 * User files are hostile input.
 */
class MediaTools {
    private const PROTOCOLS = ['-protocol_whitelist', 'file,pipe'];
    /**
     * Demuxers (names as in `ffmpeg -demuxers`) for the audio/video formats
     * the app accepts. "mov,mp4,m4a,..." and "matroska,webm" are single
     * demuxers whose names list their aliases; any listed alias matches.
     */
    private const FORMATS = ['-format_whitelist',
        'mov,mp4,m4a,3gp,3g2,mj2,matroska,webm,mp3,ogg,wav,flac,aac,asf,avi,amr,aiff,caf,wv,mpeg,mpegts,flv'];
    /** ffmpeg prints progress and one line per bad frame: keep only errors. */
    private const QUIET = ['-nostats', '-loglevel', 'error'];
    /** run() exit code when the process was killed at the timeout. */
    public const EXIT_TIMEOUT = -2;
    /** Bytes of stderr kept for the log (the tail, where the cause usually is). */
    private const STDERR_TAIL = 65536;

    public function __construct(
        private ITempManager $tempManager
    ) {
    }

    /** @return list<string> */
    public function probeDurationCommand(string $input): array {
        return array_merge(
            ['ffprobe'], self::PROTOCOLS, self::FORMATS,
            ['-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', '-i', $input]
        );
    }

    /** Re-encode a slice to mp3 mono 16 kHz, the smallest format Gemini takes well. */
    public function sliceCommand(string $input, float $startSec, float $durationSec, string $output): array {
        return array_merge(
            ['ffmpeg', '-nostdin', '-y'], self::QUIET, self::PROTOCOLS, self::FORMATS,
            [
                '-ss', sprintf('%.3f', $startSec),
                '-t', sprintf('%.3f', $durationSec),
                '-i', $input,
                '-vn', '-acodec', 'libmp3lame', '-ar', '16000', '-ac', '1', '-q:a', '4',
                $output,
            ]
        );
    }

    public function extractAudioCommand(string $input, string $output): array {
        return array_merge(
            ['ffmpeg', '-nostdin', '-y'], self::QUIET, self::PROTOCOLS, self::FORMATS,
            ['-i', $input, '-vn', '-acodec', 'libmp3lame', '-q:a', '2', $output]
        );
    }

    /**
     * True when the first bytes are a text playlist or manifest (m3u/HLS,
     * ffconcat, XML such as DASH <MPD> or Smooth Streaming) instead of real
     * media. Callers refuse such files before ffmpeg runs.
     */
    public function looksLikePlaylist(string $head): bool {
        $head = ltrim(substr($head, 0, 1024), "\xEF\xBB\xBF \t\r\n");
        return (bool)preg_match('/^(#EXTM3U|ffconcat\b|<\?xml\b|<MPD\b|<SmoothStreamingMedia\b)/i', $head);
    }

    /**
     * Audio or video according to the bytes (libmagic), whatever the file
     * name or the type Nextcloud derived from the extension says.
     */
    public function isMediaContent(string $bytes): bool {
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer(substr($bytes, 0, 1024 * 1024));
        return str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/ogg';
    }

    /**
     * Run an argv array with a wall-clock timeout. stderr goes to a private
     * temp file instead of a pipe: a pipe fills at 64 KB and, while PHP waits
     * on stdout, ffmpeg would block forever writing one error per corrupt
     * frame. stdout (small: ffprobe's duration) is drained with
     * stream_select while the process runs. On timeout the process is killed.
     *
     * Returns [exitCode, stdout, stderrTail]; exitCode is -1 when the binary
     * could not be started and EXIT_TIMEOUT when it was killed.
     *
     * @param list<string> $argv
     * @return array{0:int,1:string,2:string}
     */
    public function run(array $argv, int $timeoutSeconds = 1800): array {
        $errFile = $this->tempManager->getTemporaryFile('.log');
        if (!is_string($errFile) || $errFile === '') {
            return [-1, '', ''];
        }
        try {
            $proc = @proc_open($argv, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $errFile, 'w'],
            ], $pipes);
            if (!is_resource($proc)) {
                return [-1, '', ''];
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);

            $stdout = '';
            $exitCode = -1;
            $deadline = microtime(true) + max(1, $timeoutSeconds);
            while (true) {
                $read = [$pipes[1]];
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, 200000) > 0) {
                    $stdout .= (string)fread($pipes[1], 65536);
                }
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    // The exit code is only reported once: keep it here,
                    // proc_close() would return -1 afterwards.
                    $exitCode = (int)$status['exitcode'];
                    $stdout .= (string)stream_get_contents($pipes[1]);
                    break;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($proc, 9);
                    $exitCode = self::EXIT_TIMEOUT;
                    break;
                }
            }
            fclose($pipes[1]);
            proc_close($proc);
            return [$exitCode, $stdout, $this->tail($errFile)];
        } finally {
            @unlink($errFile);
        }
    }

    private function tail(string $file): string {
        $size = (int)@filesize($file);
        if ($size <= 0) {
            return '';
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > self::STDERR_TAIL) {
            fseek($fh, -self::STDERR_TAIL, SEEK_END);
        }
        $data = (string)stream_get_contents($fh);
        fclose($fh);
        return $data;
    }
}
