<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp;

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

        // Note: The Neos 8 signal Node::nodePropertyChanged no longer exists in Neos 9.
        // Review status clearing on approval is now handled by the CommandHook
        // (ReviewStatusCommandHookFactory) registered in Settings.yaml.
    }
}
