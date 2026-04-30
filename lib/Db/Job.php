<?php
declare(strict_types=1);

namespace OCA\Audiolog\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getSourcePath()
 * @method void setSourcePath(?string $sourcePath)
 * @method string|null getOutputTypes()
 * @method void setOutputTypes(?string $outputTypes)
 * @method string|null getPrompt()
 * @method void setPrompt(?string $prompt)
 * @method string|null getResultText()
 * @method void setResultText(?string $resultText)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method int|null getTokensUsed()
 * @method void setTokensUsed(?int $tokensUsed)
 * @method int|null getDurationSeconds()
 * @method void setDurationSeconds(?int $durationSeconds)
 * @method int|null getStartedAt()
 * @method void setStartedAt(?int $startedAt)
 * @method int|null getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getProgressCurrent()
 * @method void setProgressCurrent(?int $progressCurrent)
 * @method int|null getProgressTotal()
 * @method void setProgressTotal(?int $progressTotal)
 */
class Job extends Entity {
    protected $userId = '';
    protected $type = '';
    protected $status = 'pending';
    protected $sourcePath;
    protected $outputTypes;
    protected $prompt;
    protected $resultText;
    protected $error;
    protected $tokensUsed;
    protected $durationSeconds;
    protected $startedAt;
    protected $finishedAt;
    protected $createdAt = 0;
    protected $progressCurrent;
    protected $progressTotal;

    public function __construct() {
        $this->addType('user_id', 'string');
        $this->addType('type', 'string');
        $this->addType('status', 'string');
        $this->addType('source_path', 'string');
        $this->addType('output_types', 'string');
        $this->addType('prompt', 'string');
        $this->addType('result_text', 'string');
        $this->addType('error', 'string');
        $this->addType('tokens_used', 'integer');
        $this->addType('duration_seconds', 'integer');
        $this->addType('started_at', 'integer');
        $this->addType('finished_at', 'integer');
        $this->addType('created_at', 'integer');
        $this->addType('progress_current', 'integer');
        $this->addType('progress_total', 'integer');
    }
}
