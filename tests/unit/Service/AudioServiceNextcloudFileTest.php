<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /**
     * R2: a ".mp4" in Nextcloud whose content is a DASH manifest pointing at
     * file:// paths of another user never reaches ffmpeg.
     */
    public function testDashManifestDisguisedAsVideoIsRefused(): void {
        $fs = new FakeFs($this);
        $mpd = "<?xml version=\"1.0\"?>\n<MPD xmlns=\"urn:mpeg:dash:schema:mpd:2011\" type=\"static\"><Period><AdaptationSet><Representation id=\"a\"><BaseURL>file:///var/www/data/bob/files/Audiolog/x/x.webm</BaseURL></Representation></AdaptationSet></Period></MPD>";
        $fs->addFile('Videos/meeting.mp4', $mpd, 'video/mp4');
        $media = $this->createMock(\OCA\Audiolog\Service\MediaTools::class);
        $media->method('looksLikePlaylist')->willReturnCallback(fn ($h) => (new \OCA\Audiolog\Service\MediaTools($this->createMock(\OCP\ITempManager::class)))->looksLikePlaylist($h));
        $media->method('isMediaContent')->willReturnCallback(fn ($b) => (new \OCA\Audiolog\Service\MediaTools($this->createMock(\OCP\ITempManager::class)))->isMediaContent($b));
        $media->expects($this->never())->method('run');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder(), 'media' => $media]);

        try {
            $svc->processNextcloudFile('alice', 'Videos/meeting.mp4', '', ['transcricao']);
            $this->fail('manifest must be refused');
        } catch (\OCA\Audiolog\Exception\InvalidInputException $e) {
            $this->assertSame(415, $e->getHttpStatus());
        }
    }

    /** R2: whatever the extension says, non-media bytes are refused (finfo on the content). */
    public function testTextNamedLikeAudioIsRefused(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Music/notes.mp3', "lorem ipsum dolor sit amet\n", 'audio/mpeg');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        $this->expectException(\OCA\Audiolog\Exception\InvalidInputException::class);
        $svc->processNextcloudFile('alice', 'Music/notes.mp3', '', ['transcricao']);
    }
}
