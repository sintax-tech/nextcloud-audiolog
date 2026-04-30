<?php
declare(strict_types=1);

namespace OCA\Audiolog\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates oc_audiolog_jobs — central table tracking every processing run
 * (sync or async). Used by:
 *  - rate limiting (count rows per user per day)
 *  - async jobs (status polling from frontend)
 *  - usage history (richer than the per-file .meta.json sidecar)
 */
class Version110100Date20260430 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('audiolog_jobs')) {
            $table = $schema->createTable('audiolog_jobs');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                // 'process' | 'refine' | 'split' | 'live'
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'pending',
                // 'pending' | 'running' | 'completed' | 'failed' | 'cancelled'
            ]);
            $table->addColumn('source_path', Types::STRING, [
                'notnull' => false,
                'length' => 1024,
            ]);
            $table->addColumn('output_types', Types::STRING, [
                'notnull' => false,
                'length' => 256,
            ]);
            $table->addColumn('prompt', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('result_text', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('error', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('tokens_used', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('duration_seconds', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('progress_current', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('progress_total', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('started_at', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('finished_at', Types::BIGINT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'created_at'], 'audiolog_user_date_idx');
            $table->addIndex(['status'], 'audiolog_status_idx');
        }

        return $schema;
    }
}
