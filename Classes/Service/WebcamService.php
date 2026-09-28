<?php

declare(strict_types=1);

namespace Lala\Webcam\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeAggregate;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\Fusion\Cache\CacheFlushingStrategy;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Neos\Neos\Fusion\Cache\FlushNodeAggregateRequest;
use Lala\Webcam\Domain\Model\Snapshot;
use Lala\Webcam\Domain\Repository\SnapshotRepository;

#[Flow\Scope('singleton')]
class WebcamService
{
    public const NODE_TYPE = 'Lala.Webcam:Content.Webcam';
    public const RESOURCE_COLLECTION = 'lalaWebcam';

    /**
     * Matches the tokens suggested by the inspector and keeps them safe to paste into a shell
     */
    public const UPLOAD_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,255}$/';

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected SnapshotRepository $snapshotRepository;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected ContentCacheFlusher $contentCacheFlusher;

    #[Flow\InjectConfiguration(path: 'keepSnapshots')]
    protected int $keepSnapshots;

    /**
     * Returns the webcam if it is published in the live workspace and neither hidden nor removed
     */
    public function findWebcam(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId): ?NodeAggregate
    {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $webcam = $contentRepository->getContentGraph(WorkspaceName::forLive())->findNodeAggregateById($nodeAggregateId);
        if ($webcam === null) {
            return null;
        }
        if (!$contentRepository->getNodeTypeManager()->getNodeType($webcam->nodeTypeName)?->isOfType(self::NODE_TYPE)) {
            return null;
        }

        // Inherited tags count as well, so hiding the page hides its webcam
        $invisibleDimensions = $webcam->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), false)
            ->getUnion($webcam->getCoveredDimensionsTaggedBy(NeosSubtreeTag::disabled(), false));
        if ($invisibleDimensions->count() === $webcam->coveredDimensionSpacePoints->count()) {
            return null;
        }

        return $webcam;
    }

    public function isValidUploadToken(NodeAggregate $webcam, string $token): bool
    {
        if (!preg_match(self::UPLOAD_TOKEN_PATTERN, $token)) {
            return false;
        }
        foreach ($webcam->getNodes() as $node) {
            $expectedToken = (string)$node->getProperty('uploadToken');
            if (preg_match(self::UPLOAD_TOKEN_PATTERN, $expectedToken) && hash_equals($expectedToken, $token)) {
                return true;
            }
        }
        return false;
    }

    public function addSnapshot(NodeAggregate $webcam, string $jpeg, int $width, int $height): Snapshot
    {
        $previousSnapshots = $this->snapshotRepository->findByWebcam($webcam->contentRepositoryId, $webcam->nodeAggregateId);

        $resource = $this->resourceManager->importResourceFromContent($jpeg, 'webcam.jpg', self::RESOURCE_COLLECTION);
        $snapshot = new Snapshot($webcam->contentRepositoryId, $webcam->nodeAggregateId, $resource, $width, $height);
        $this->snapshotRepository->add($snapshot);
        // An unchanged image has the same SHA1, so the new resource must be persisted before the old
        // one is deleted. Otherwise deleteResource() would remove the file both of them point to.
        $this->persistenceManager->persistAll();

        foreach (array_slice($previousSnapshots, max($this->keepSnapshots - 1, 0)) as $outdatedSnapshot) {
            $this->removeSnapshot($outdatedSnapshot);
        }

        $this->persistenceManager->persistAll();
        $this->flushContentCache($webcam);

        return $snapshot;
    }

    public function findLatestSnapshot(ContentRepositoryId $contentRepositoryId, NodeAggregateId $nodeAggregateId): ?Snapshot
    {
        return $this->snapshotRepository->findLatest($contentRepositoryId, $nodeAggregateId);
    }

    public function getSnapshotUri(Snapshot $snapshot): string
    {
        return (string)$this->resourceManager->getPublicPersistentResourceUri($snapshot->getResource());
    }

    /**
     * Deletes the snapshots of all webcams that no longer exist in the given live content graph
     */
    public function removeSnapshotsOfRemovedWebcams(ContentGraphInterface $liveContentGraph): void
    {
        $contentRepositoryId = $liveContentGraph->getContentRepositoryId();
        $removedAny = false;
        foreach ($this->snapshotRepository->findWebcamIds($contentRepositoryId) as $nodeAggregateId) {
            if ($liveContentGraph->findNodeAggregateById($nodeAggregateId) !== null) {
                continue;
            }
            foreach ($this->snapshotRepository->findByWebcam($contentRepositoryId, $nodeAggregateId) as $snapshot) {
                $this->removeSnapshot($snapshot);
                $removedAny = true;
            }
        }
        if ($removedAny) {
            $this->persistenceManager->persistAll();
        }
    }

    private function removeSnapshot(Snapshot $snapshot): void
    {
        $this->snapshotRepository->remove($snapshot);
        $this->resourceManager->deleteResource($snapshot->getResource());
    }

    private function flushContentCache(NodeAggregate $webcam): void
    {
        $contentGraph = $this->contentRepositoryRegistry->get($webcam->contentRepositoryId)->getContentGraph($webcam->workspaceName);
        $this->contentCacheFlusher->flushNodeAggregate(
            FlushNodeAggregateRequest::create(
                $webcam->contentRepositoryId,
                $webcam->workspaceName,
                $webcam->nodeAggregateId,
                $webcam->nodeTypeName,
                $contentGraph->findAncestorNodeAggregateIds($webcam->nodeAggregateId),
            ),
            CacheFlushingStrategy::IMMEDIATE
        );
    }
}
