<?php

declare(strict_types=1);

namespace Lala\Webcam\CatchUpHook;

use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Lala\Webcam\Service\WebcamService;

/**
 * @implements CatchUpHookFactoryInterface<ContentGraphReadModelInterface>
 */
final class WebcamRemovalCatchUpHookFactory implements CatchUpHookFactoryInterface
{
    public function __construct(
        private readonly WebcamService $webcamService,
    ) {
    }

    public function build(CatchUpHookFactoryDependencies $dependencies): CatchUpHookInterface
    {
        return new WebcamRemovalCatchUpHook($dependencies->projectionState, $this->webcamService);
    }
}
