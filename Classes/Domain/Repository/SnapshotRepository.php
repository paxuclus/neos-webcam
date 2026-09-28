<?php

declare(strict_types=1);

namespace Lala\Webcam\Domain\Repository;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Repository;
use Lala\Webcam\Domain\Model\Snapshot;

#[Flow\Scope('singleton')]
class SnapshotRepository extends Repository
{
    /**
     * @return list<Snapshot> newest first
     */
    public function findByWebcam(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId): array
    {
        return $this->createQueryBuilder('snapshot')
            ->where('snapshot.contentRepositoryId = :contentRepositoryId')
            ->andWhere('snapshot.nodeAggregateId = :nodeAggregateId')
            ->orderBy('snapshot.createdAt', 'DESC')
            ->setParameter('contentRepositoryId', $contentRepositoryId->value)
            ->setParameter('nodeAggregateId', $nodeAggregateId->value)
            ->getQuery()
            ->getResult();
    }

    public function findLatest(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId): ?Snapshot
    {
        return $this->createQueryBuilder('snapshot')
            ->where('snapshot.contentRepositoryId = :contentRepositoryId')
            ->andWhere('snapshot.nodeAggregateId = :nodeAggregateId')
            ->orderBy('snapshot.createdAt', 'DESC')
            ->setParameter('contentRepositoryId', $contentRepositoryId->value)
            ->setParameter('nodeAggregateId', $nodeAggregateId->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<NodeAggregateId>
     */
    public function findWebcamIds(ContentRepositoryId $contentRepositoryId): array
    {
        $ids = $this->createQueryBuilder('snapshot')
            ->select('DISTINCT snapshot.nodeAggregateId')
            ->where('snapshot.contentRepositoryId = :contentRepositoryId')
            ->setParameter('contentRepositoryId', $contentRepositoryId->value)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(NodeAggregateId::fromString(...), $ids);
    }
}
