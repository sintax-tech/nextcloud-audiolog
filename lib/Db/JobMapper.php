<?php
declare(strict_types=1);

namespace OCA\Audiolog\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Job>
 */
class JobMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'audiolog_jobs', Job::class);
    }

    public function find(int $id): ?Job {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function countSince(string $userId, int $sinceTimestamp): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($sinceTimestamp, IQueryBuilder::PARAM_INT)));
        $cursor = $qb->executeQuery();
        $row = $cursor->fetch(\PDO::FETCH_NUM);
        $cursor->closeCursor();
        return (int)($row[0] ?? 0);
    }

    /**
     * @return Job[]
     */
    public function findRecentForUser(string $userId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Pending/running jobs older than $cutoff seconds — used to mark zombies as failed.
     */
    public function findStaleRunning(int $cutoffTimestamp): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(['pending', 'running'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($cutoffTimestamp, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }
}
