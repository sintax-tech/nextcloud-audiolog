<?php
declare(strict_types=1);

namespace OCA\Audiolog\Service;

/**
 * ffmpeg/ffprobe invocations, always as argv arrays through proc_open (no
 * shell) and always with `-protocol_whitelist file,pipe` before the input:
 * user files are hostile input, and a playlist (HLS/concat) disguised as
 * audio/video would otherwise make the demuxer fetch http(s) URLs from the
 * server (blind SSRF into the internal network).
 */
class MediaTools {
    private const PROTOCOLS = ['-protocol_whitelist', 'file,pipe'];

    /** @return list<string> */
    public function probeDurationCommand(string $input): array {
        return array_merge(
            ['ffprobe'], self::PROTOCOLS,
            ['-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', '-i', $input]
        );
    }

    /** Re-encode a slice to mp3 mono 16 kHz, the smallest format Gemini takes well. */
    public function sliceCommand(string $input, float $startSec, float $durationSec, string $output): array {
        return array_merge(
            ['ffmpeg', '-nostdin', '-y'], self::PROTOCOLS,
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
            ['ffmpeg', '-nostdin', '-y'], self::PROTOCOLS,
            ['-i', $input, '-vn', '-acodec', 'libmp3lame', '-q:a', '2', $output]
        );
    }

    /**
     * True when the first bytes are a text playlist (m3u/HLS or ffconcat)
     * instead of real media. Callers refuse such files before ffmpeg runs.
     */
    public function looksLikePlaylist(string $head): bool {
        $head = ltrim(substr($head, 0, 1024), "\xEF\xBB\xBF \t\r\n");
        return (bool)preg_match('/^(#EXTM3U|ffconcat\b)/i', $head);
    }

    /**
     * Run an argv array. Returns [exitCode, stdout, stderr]; exitCode is -1
     * when the binary could not be started.
     *
     * @param list<string> $argv
     * @return array{0:int,1:string,2:string}
     */
    public function run(array $argv): array {
        $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return [-1, '', ''];
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $stdout, $stderr];
    }
}
