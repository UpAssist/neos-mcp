<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use UpAssist\Neos\Mcp\Controller\McpBridgeController;
use UpAssist\Neos\Mcp\Service\ReviewStatusService;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();

        $dispatcher->connect(
            McpBridgeController::class,
            'nodeMutated',
            ReviewStatusService::class,
            'handleNodeMutated'
        );

        $dispatcher->connect(
            Node::class,
            'nodePropertyChanged',
            ReviewStatusService::class,
            'handleReviewStatusChanged'
        );
    }
}
