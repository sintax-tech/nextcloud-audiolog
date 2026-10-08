<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use PHPUnit\Framework\TestCase;

class AudioServiceNextcloudFileTest extends TestCase {
    /** M1: files picked from Nextcloud respect max_file_size as well. */
    public function testNextcloudFileAboveMaxFileSizeIsRejected(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Music/big.wav', str_repeat('a', 2 * 1024 * 1024), 'audio/wav');
        $svc = TestHelpers::audioService($this, [
            'rootFolder' => $fs->rootFolder(),
            'config' => TestHelpers::config($this, ['max_file_size' => '1', 'max_jobs_per_user_per_day' => '0']),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/1 MB/');
        $svc->processNextcloudFile('alice', 'Music/big.wav', '', ['transcricao']);
    }

    /** M2: a "video" whose content is an HLS playlist never reaches ffmpeg. */
    public function testPlaylistDisguisedAsVideoIsRefused(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Videos/meeting.mp4', "#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nhttp://169.254.169.254/latest/meta-data\n", 'video/mp4');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        try {
            $svc->processNextcloudFile('alice', 'Videos/meeting.mp4', '', ['transcricao']);
            $this->fail('playlist must be refused');
        } catch (\OCA\Audiolog\Exception\InvalidInputException $e) {
            $this->assertSame(415, $e->getHttpStatus());
        }
    }
}
