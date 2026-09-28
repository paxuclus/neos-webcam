<?php

declare(strict_types=1);

namespace Lala\Webcam\CatchUpHook;

use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\EventEnvelope;
use Lala\Webcam\Service\WebcamService;

/**
 * Deletes the snapshots of a webcam once its node is removed from the live workspace.
 *
 * Deleting a node in Neos only soft removes it at first, the actual NodeAggregateWasRemoved
 * event follows when the soft removal is garbage collected. The event only names the root of
 * the removed subtree, so instead of resolving descendants, all webcams with snapshots are
 * checked against the live graph once the batch is committed.
 */
final class WebcamRemovalCatchUpHook implements CatchUpHookInterface
{
    private bool $isActive = false;

    private bool $nodesWereRemoved = false;

    public function __construct(
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly WebcamService $webcamService,
    ) {
    }

    public function onBeforeCatchUp(SubscriptionStatus $subscriptionStatus): void
    {
        // While booting or replaying, the graph is incomplete and existing webcams would look removed
        $this->isActive = $subscriptionStatus === SubscriptionStatus::ACTIVE;
    }

    public function onBeforeEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
    }

    public function onAfterEvent(EventInterface $eventInstance, EventEnvelope $eventEnvelope): void
    {
        if ($eventInstance instanceof NodeAggregateWasRemoved && $eventInstance->getWorkspaceName()->isLive()) {
            $this->nodesWereRemoved = true;
        }
    }

    public function onAfterBatchCompleted(): void
    {
        if (!$this->isActive || !$this->nodesWereRemoved) {
            return;
        }
        $this->nodesWereRemoved = false;
        $this->webcamService->removeSnapshotsOfRemovedWebcams(
            $this->contentGraphReadModel->getContentGraph(WorkspaceName::forLive())
        );
    }

    public function onAfterCatchUp(): void
    {
    }
}
