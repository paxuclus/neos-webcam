<?php

declare(strict_types=1);

namespace Lala\Webcam\Eel;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Lala\Webcam\Service\WebcamService;

class WebcamHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected WebcamService $webcamService;

    /**
     * @return array{uri: string, width: int, height: int, createdAt: \DateTimeImmutable}|null
     */
    public function latestSnapshot(Node $node): ?array
    {
        $snapshot = $this->webcamService->findLatestSnapshot($node->contentRepositoryId, $node->aggregateId);
        if ($snapshot === null) {
            return null;
        }

        return [
            'uri' => $this->webcamService->getSnapshotUri($snapshot),
            'width' => $snapshot->getWidth(),
            'height' => $snapshot->getHeight(),
            'createdAt' => $snapshot->getCreatedAt(),
        ];
    }

    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}
