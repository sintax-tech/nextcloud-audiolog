<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Tests\Unit\Support\FakeFs;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * M7: the live "refine" pass no longer sends a Portuguese prompt from the
 * browser; the server builds it in the language the admin configured.
 */
class RefinePromptLanguageTest extends TestCase {
    /** @dataProvider languages */
    public function testRefinePromptFollowsConfiguredLanguage(string $lang, string $expected): void {
        $fs = new FakeFs($this);
        $fs->addFile('Audiolog/Live/Live.webm', TestHelpers::wavBytes(), 'audio/webm');
        $sent = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['candidates' => [['content' => ['parts' => [['text' => '[Falante 1] ok']]]]]]));
        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(function ($uri, $opts) use (&$sent, $response) {
            $sent[] = $opts['json']['contents'][0]['parts'][0]['text'] ?? '';
            return $response;
        });
        $cs = $this->createMock(IClientService::class);
        $cs->method('newClient')->willReturn($client);
        $svc = TestHelpers::audioService($this, [
            'rootFolder' => $fs->rootFolder(),
            'clientService' => $cs,
            'config' => TestHelpers::config($this, [
                'ai_provider' => 'gemini', 'api_key' => 'k', 'language' => $lang,
                'max_jobs_per_user_per_day' => '0', 'long_audio_split_threshold' => '0',
            ]),
        ]);

        $svc->processNextcloudFile('alice', 'Audiolog/Live/Live.webm', '', ['transcricao'], 'Live');

        $this->assertCount(1, $sent);
        $this->assertStringContainsString($expected, $sent[0]);
        $this->assertStringContainsString('[Falante 1]', $sent[0]);
        if ($lang !== 'pt') {
            $this->assertStringNotContainsString('português', $sent[0]);
        }
    }

    public static function languages(): array {
        return [['en', 'English'], ['es', 'español'], ['pt', 'português brasileiro']];
    }

    public function testBrowserNoLongerHardcodesPortuguesePrompt(): void {
        $js = file_get_contents(__DIR__ . '/../../../js/audiolog-main.js');
        $this->assertStringNotContainsString('português brasileiro', $js);
        $this->assertStringNotContainsString("toLocaleString('pt-BR')", $js);
    }
}
