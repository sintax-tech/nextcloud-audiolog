<?php
declare(strict_types=1);

namespace OCA\Audiolog\BackgroundJob;

use OCA\Audiolog\Db\JobMapper;
use OCA\Audiolog\Service\AudioService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background processor that drains a queued processAudio/processNextcloudFile
 * call. Dispatched from ApiController::process when the client passes
 * `async=true`. The frontend polls /api/status/{jobId} until status moves to
 * completed or failed.
 *
 * Argument shape (set via JobList::add):
 *   [
 *     'jobId'       => int,        // primary key in oc_audiolog_jobs
 *     'userId'      => string,
 *     'kind'        => 'process' | 'process_nc',
 *     'ncPath'      => string|null,// for kind=process_nc
 *     'tmpPath'     => string|null,// for kind=process (uploaded file already on disk)
 *     'filename'    => string,
 *     'mimeType'    => string,
 *     'prompt'      => string,
 *     'outputTypes' => array<string>,
 *     'title'       => string,
 *   ]
 */
class ProcessAudioJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private AudioService $audioService,
        private JobMapper $jobMapper,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $jobId = (int)($argument['jobId'] ?? 0);
        if ($jobId <= 0) {
            $this->logger->error('Audiolog ProcessAudioJob: missing jobId');
            return;
        }
        $job = $this->jobMapper->find($jobId);
        if (!$job) {
            $this->logger->error('Audiolog ProcessAudioJob: job ' . $jobId . ' not found');
            return;
        }

        $job->setStatus('running');
        $job->setStartedAt($this->time->getTime());
        $this->jobMapper->update($job);

        try {
            $userId = (string)($argument['userId'] ?? '');
            $kind = (string)($argument['kind'] ?? 'process');
            $outputTypes = (array)($argument['outputTypes'] ?? ['transcricao']);
            $prompt = (string)($argument['prompt'] ?? '');
            $title = (string)($argument['title'] ?? '');

            $result = null;
            if ($kind === 'process_nc') {
                $ncPath = (string)($argument['ncPath'] ?? '');
                $result = $this->audioService->processNextcloudFile(
                    $userId, $ncPath, $prompt, $outputTypes, $title
                );
            } else {
                $tmpPath = (string)($argument['tmpPath'] ?? '');
                $filename = (string)($argument['filename'] ?? 'upload.bin');
                $mimeType = (string)($argument['mimeType'] ?? 'application/octet-stream');
                if (!is_file($tmpPath)) {
                    throw new \RuntimeException('Arquivo temporário não encontrado: ' . $tmpPath);
                }
                $result = $this->audioService->processAudio(
                    $tmpPath, $filename, $mimeType, $prompt, $outputTypes, $userId, $title
                );
                @unlink($tmpPath);
            }

            // The audioService recorded its own job entry; keep this top-level
            // job authoritative by mirroring the result text into it.
            $job->setStatus('completed');
            $job->setResultText(mb_substr((string)($result['text'] ?? ''), 0, 200000));
            $job->setFinishedAt($this->time->getTime());
            $this->jobMapper->update($job);
        } catch (\Throwable $e) {
            $this->logger->error('Audiolog ProcessAudioJob failed: ' . $e->getMessage());
            $job->setStatus('failed');
            $job->setError(mb_substr($e->getMessage(), 0, 4000));
            $job->setFinishedAt($this->time->getTime());
            $this->jobMapper->update($job);
        }
    }
}
