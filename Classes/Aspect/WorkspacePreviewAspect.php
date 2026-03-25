<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Aspect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use UpAssist\Neos\Mcp\Service\PreviewTokenService;

/**
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
     * Switch the workspace context to the preview workspace when a valid preview token is active.
     * Skips routing-phase calls (identified by the presence of currentSite in the input properties)
     * to avoid entity-privilege failures before the security framework is fully initialized.
     *
     * @Flow\Around("method(Neos\ContentRepository\Domain\Service\ContextFactory->create())")
     */
    public function applyWorkspacePreview(JoinPointInterface $joinPoint): mixed
    {
        if ($this->previewTokenService->hasActivePreview()) {
            $contextProperties = $joinPoint->getMethodArgument('contextProperties');
            // Skip during routing/error-page phases: those callers always pass currentSite explicitly.
            if (!isset($contextProperties['currentSite'])) {
                // Only override if not already targeting a specific non-live workspace
                if (!isset($contextProperties['workspaceName']) || $contextProperties['workspaceName'] === 'live') {
                    $contextProperties['workspaceName'] = $this->previewTokenService->getActiveWorkspace();
                    $joinPoint->setMethodArgument('contextProperties', $contextProperties);
                }
            }
        }
        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * During an active preview, pretend the context is live so NodeController::showAction() passes
     * its isLive() guard. The node itself is already in the preview workspace (switched above),
     * so Fusion naturally renders content from that workspace via getChildNodes() etc.
     *
     * @Flow\Around("method(Neos\Neos\Domain\Service\ContentContext->isLive())")
     */
    public function fakeIsLiveForPreview(JoinPointInterface $joinPoint): bool
    {
        if ($this->previewTokenService->hasActivePreview()) {
            return true;
        }
        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }
}
