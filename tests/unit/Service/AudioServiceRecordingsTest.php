<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use PHPUnit\Framework\TestCase;

/**
 * A5: recordings saved with a title live in Audiolog/<title>/<title>.ext.
 * Rename and output linking must work there, not only at the Audiolog root.
 */
class AudioServiceRecordingsTest extends TestCase {
    private function meta(FakeFs $fs, string $rel): array {
        return json_decode((string)$fs->content($rel), true) ?: [];
    }

    public function testRenameRecordingInsideTitleFolder(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Audiolog/Reuniao/Reuniao.webm');
        $fs->addFile('Audiolog/Reuniao/.Reuniao.webm.meta.json', json_encode(['title' => 'Reuniao', 'outputs' => [['type' => 'note', 'path' => 'Audiolog/Reuniao/n.md']]]), 'application/json');
        // A homonym at the root must stay untouched.
        $fs->addFile('Audiolog/Reuniao.webm');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        $result = $svc->renameRecording('alice', 'Audiolog/Reuniao/Reuniao.webm', 'Kickoff');

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame('Audiolog/Reuniao/Kickoff.webm', $result['newPath']);
        $this->assertTrue($fs->exists('Audiolog/Reuniao/Kickoff.webm'));
        $this->assertFalse($fs->exists('Audiolog/Reuniao/Reuniao.webm'));
        $this->assertTrue($fs->exists('Audiolog/Reuniao.webm'));
        $meta = $this->meta($fs, 'Audiolog/Reuniao/.Kickoff.webm.meta.json');
        $this->assertSame('Kickoff', $meta['title']);
        $this->assertSame('Audiolog/Reuniao/Kickoff.webm', $meta['audioPath']);
        $this->assertCount(1, $meta['outputs']);
        $this->assertFalse($fs->exists('Audiolog/Reuniao/.Reuniao.webm.meta.json'));
    }

    public function testRenameAtRootStillWorks(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Audiolog/Audio_1.webm');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        $result = $svc->renameRecording('alice', 'Audiolog/Audio_1.webm', 'Novo nome');

        $this->assertSame('Audiolog/Novo_nome.webm', $result['newPath'] ?? null, json_encode($result));
        $this->assertTrue($fs->exists('Audiolog/Novo_nome.webm'));
    }

    public function testSavedNoteIsLinkedInMetaOfRecordingInSubfolder(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Audiolog/Reuniao/Reuniao.webm');
        $fs->addFile('Audiolog/Reuniao/.Reuniao.webm.meta.json', json_encode(['title' => 'Reuniao', 'outputs' => []]), 'application/json');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        $saved = $svc->saveToNotes('alice', 'Ata', 'conteúdo', 'Audiolog/Reuniao/Reuniao.webm');

        $this->assertStringStartsWith('Audiolog/Reuniao/Ata_', $saved['path']);
        $meta = $this->meta($fs, 'Audiolog/Reuniao/.Reuniao.webm.meta.json');
        $this->assertSame($saved['path'], $meta['outputs'][0]['path'] ?? null);
    }

    public function testDeleteWithOutputsRemovesNoteOfSubfolderRecording(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Audiolog/Reuniao/Reuniao.webm');
        $fs->addFile('Audiolog/Reuniao/.Reuniao.webm.meta.json', json_encode(['title' => 'Reuniao', 'outputs' => []]), 'application/json');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);
        $saved = $svc->saveToNotes('alice', 'Ata', 'conteúdo', 'Audiolog/Reuniao/Reuniao.webm');

        $result = $svc->deleteRecording('alice', 'Audiolog/Reuniao/Reuniao.webm', true);

        $this->assertSame(1, $result['deletedOutputs'] ?? null, json_encode($result));
        $this->assertFalse($fs->exists($saved['path']));
    }

    public function testRenameRejectsPathOutsideAudiolog(): void {
        $fs = new FakeFs($this);
        $fs->addFile('Private/secret.webm');
        $svc = TestHelpers::audioService($this, ['rootFolder' => $fs->rootFolder()]);

        $result = $svc->renameRecording('alice', 'Audiolog/../Private/secret.webm', 'x');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue($fs->exists('Private/secret.webm'));
    }
}
