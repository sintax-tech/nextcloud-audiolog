<?php
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\BackgroundJob;

use OCA\Audiolog\BackgroundJob\ProcessAudioJob;
use OCA\Audiolog\Db\Job;
use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCA\Audiolog\Service\CryptoHelper;
use OCA\Audiolog\Service\SecretRedactor;
use OCA\Audiolog\Tests\Unit\Support\RecordingLogger;
use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

class ProcessAudioJobTest extends TestCase {
    public function testProviderErrorIsMaskedInJobErrorAndLog(): void {
        $job = new Job();
        $job->setId(7);
        $job->setUserId('alice');

        $mapper = $this->createMock(JobMapper::class);
        $mapper->method('find')->willReturn($job);

        $audio = $this->createMock(AudioService::class);
        $audio->method('processNextcloudFile')->willThrowException(new \RuntimeException(
            'Client error: `POST https://generativelanguage.googleapis.com/v1beta/models/m:generateContent?key='
            . TestHelpers::GEMINI_KEY . '` resulted in a `400 Bad Request` response'
        ));

        $crypto = $this->createMock(CryptoHelper::class);
        $crypto->method('decrypt')->willReturnArgument(0);
        $config = TestHelpers::config($this, ['api_key' => TestHelpers::GEMINI_KEY]);
        $logger = new RecordingLogger();

        $bgJob = new ProcessAudioJob(
            $this->createMock(ITimeFactory::class),
            $audio,
            $mapper,
            $logger,
            new SecretRedactor($config, $crypto),
        );
        $run = new \ReflectionMethod($bgJob, 'run');
        $run->invoke($bgJob, ['jobId' => 7, 'userId' => 'alice', 'kind' => 'process_nc', 'ncPath' => 'Audiolog/a.wav']);

        $this->assertSame('failed', $job->getStatus());
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, (string)$job->getError());
        $this->assertStringNotContainsString(TestHelpers::GEMINI_KEY, $logger->dump());
    }
}
