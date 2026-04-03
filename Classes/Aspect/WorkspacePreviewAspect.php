<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Aspect;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use UpAssist\Neos\Mcp\Service\PreviewTokenService;

/**
 * Switches the workspace context for frontend preview when a valid MCP preview token is active.
 *
 * In Neos 9, the frontend NodeController receives a NodeAddress JSON string.
 * This aspect rewrites the workspace in that address to the preview workspace
 * and bypasses the isLive() guard.
 *
 * @Flow\Aspect
 */
class WorkspacePreviewAspect
{
    /**
     * @Flow\Inject
     * @var PreviewTokenService
     */
    protected $previewTokenService;

    /**
     * Intercept NodeController::showAction() to switch the NodeAddress workspace
     * from 'live' to the preview workspace when a valid preview token is active.
     *
     * @Flow\Around("method(Neos\Neos\Controller\Frontend\NodeController->showAction())")
     */
    public function applyWorkspacePreview(JoinPointInterface $joinPoint): mixed
    {
        if (!$this->previewTokenService->hasActivePreview()) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $nodeJson = $joinPoint->getMethodArgument('node');
        $nodeAddress = NodeAddress::fromJsonString($nodeJson);

        // Only override if targeting live workspace
        if ($nodeAddress->workspaceName->isLive()) {
            $previewAddress = NodeAddress::create(
                $nodeAddress->contentRepositoryId,
                WorkspaceName::fromString($this->previewTokenService->getActiveWorkspace()),
                $nodeAddress->dimensionSpacePoint,
                $nodeAddress->aggregateId,
            );
            $joinPoint->setMethodArgument('node', $previewAddress->toJson());
        }

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * During an active preview, bypass the isLive() check in NodeController::showAction().
     * The showAction throws NodeNotFoundException when !isLive() — this aspect prevents that
     * by making the workspace appear live.
     *
     * We hook into WorkspaceName::isLive() to return true for the preview workspace.
     *
     * @Flow\Around("method(Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName->isLive())")
     */
    public function fakeIsLiveForPreview(JoinPointInterface $joinPoint): bool
    {
        if ($this->previewTokenService->hasActivePreview()) {
            $workspaceName = $joinPoint->getProxy();
            if ($workspaceName instanceof WorkspaceName
                && $workspaceName->value === $this->previewTokenService->getActiveWorkspace()) {
                return true;
            }
        }
        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }
}
