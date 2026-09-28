<?php

declare(strict_types=1);

namespace Lala\Webcam\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\PersistentResource;

/**
 * One image uploaded by a webcam
 *
 * @Flow\Entity
 * @ORM\Table(indexes={@ORM\Index(name="webcam", columns={"contentrepositoryid", "nodeaggregateid"})})
 */
class Snapshot
{
    /**
     * @ORM\Column(length=16)
     */
    protected string $contentRepositoryId;

    /**
     * @ORM\Column(length=64)
     */
    protected string $nodeAggregateId;

    /**
     * @ORM\OneToOne
     * @ORM\JoinColumn(nullable=false)
     */
    protected PersistentResource $resource;

    protected int $width;

    protected int $height;

    protected \DateTimeImmutable $createdAt;

    public function __construct(
        ContentRepositoryId $contentRepositoryId,
        NodeAggregateId $nodeAggregateId,
        PersistentResource $resource,
        int $width,
        int $height,
    ) {
        $this->contentRepositoryId = $contentRepositoryId->value;
        $this->nodeAggregateId = $nodeAggregateId->value;
        $this->resource = $resource;
        $this->width = $width;
        $this->height = $height;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getContentRepositoryId(): ContentRepositoryId
    {
        return ContentRepositoryId::fromString($this->contentRepositoryId);
    }

    public function getNodeAggregateId(): NodeAggregateId
    {
        return NodeAggregateId::fromString($this->nodeAggregateId);
    }

    public function getResource(): PersistentResource
    {
        return $this->resource;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
