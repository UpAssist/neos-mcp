<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Aspect;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\ContentRepositoryRegistry\SubgraphCachingInMemory\SubgraphCachePool;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Controller\Frontend\ContentSubgraphCacheWarmup;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\FrontendRouting\Exception\InvalidShortcutException;
use Neos\Neos\FrontendRouting\Exception\NodeNotFoundException;
use Neos\Neos\FrontendRouting\NodeShortcutResolver;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Utility\ObjectAccess;
use UpAssist\Neos\Mcp\Service\PreviewTokenService;

/**
 * Handles frontend rendering of a node in the MCP preview workspace when a valid
 * `_mcpPreview` token has been set on the request by {@see \UpAssist\Neos\Mcp\Http\Middleware\PreviewTokenMiddleware}.
 *
 * Neos\Neos\Controller\Frontend\NodeController::showAction() hard-guards on
 * `$nodeAddress->workspaceName->isLive()` and throws NodeNotFoundException for any
 * non-live workspace — the stock controller has no anonymous preview path. We cannot
 * AOP-proxy WorkspaceName::isLive() (the class is final), so instead of proceeding
 * through the advice chain we short-circuit: this aspect performs the same happy-path
 * work showAction does (find the node in the preview workspace, resolve the site,
 * warm the subgraph cache, handle shortcuts, prime the view) against the preview
 * workspace and returns without ever executing the original body.
 *
 * Scope is strictly the showAction join point + an active preview token on the
 * per-request PreviewTokenService singleton. PolicyEnforcement still runs at its
 * outer join point against the original (public) showAction target.
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
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var SubgraphCachePool
     */
    protected $subgraphCachePool;

    /**
     * @Flow\Inject
     * @var ContentRepositoryAuthorizationService
     */
    protected $contentRepositoryAuthorizationService;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;

    /**
     * @Flow\Inject
     * @var NodeUriBuilderFactory
     */
    protected $nodeUriBuilderFactory;

    /**
     * @Flow\Inject(lazy=false)
     * @var ContentSubgraphCacheWarmup
     */
    protected ?ContentSubgraphCacheWarmup $contentSubgraphCacheWarmup = null;

    /**
     * @Flow\InjectConfiguration(path="frontend.shortcutRedirectHttpStatusCode", package="Neos.Neos")
     * @var int
     */
    protected $shortcutRedirectHttpStatusCode = 303;

    /**
     * Render the preview workspace instead of proceeding to NodeController::showAction(),
     * which would throw on any non-live workspace (line 194 of Neos NodeController).
     *
     * @Flow\Around("method(Neos\Neos\Controller\Frontend\NodeController->showAction())")
     */
    public function applyWorkspacePreview(JoinPointInterface $joinPoint): mixed
    {
        if (!$this->previewTokenService->hasActivePreview()) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $controller = $joinPoint->getProxy();
        if (!$controller instanceof ActionController) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $nodeJson = $joinPoint->getMethodArgument('node');
        $originalAddress = NodeAddress::fromJsonString($nodeJson);

        // Only override when the incoming request is for the live workspace — leave
        // editor preview requests (non-live workspace in the URL) untouched.
        if (!$originalAddress->workspaceName->isLive()) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $previewAddress = NodeAddress::create(
            $originalAddress->contentRepositoryId,
            WorkspaceName::fromString($this->previewTokenService->getActiveWorkspace()),
            $originalAddress->dimensionSpacePoint,
            $originalAddress->aggregateId,
        );

        $contentRepository = $this->contentRepositoryRegistry->get($previewAddress->contentRepositoryId);
        $visibilityConstraints = $this->contentRepositoryAuthorizationService
            ->getVisibilityConstraints($contentRepository->id, $this->securityContext->getRoles())
            ->merge(NeosVisibilityConstraints::excludeDisabled());
        $subgraph = $this->subgraphCachePool->getContentSubgraph(
            $contentRepository,
            $previewAddress->workspaceName,
            $previewAddress->dimensionSpacePoint,
            $visibilityConstraints
        );

        $nodeInstance = $subgraph->findNodeById($previewAddress->aggregateId);
        if ($nodeInstance === null) {
            throw new NodeNotFoundException(
                sprintf('Preview node not found in workspace "%s": %s', $previewAddress->workspaceName->value, $previewAddress->toJson()),
                1761223000
            );
        }

        $site = $subgraph->findClosestNode(
            $previewAddress->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );
        if ($site === null) {
            throw new NodeNotFoundException(
                sprintf('Site node for preview target %s could not be resolved.', $previewAddress->toJson()),
                1761223001
            );
        }

        $this->contentSubgraphCacheWarmup?->fillCacheWithContentNodes($previewAddress->aggregateId, $subgraph);

        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $nodeType = $nodeTypeManager->getNodeType($nodeInstance->nodeTypeName)
            ?? $nodeTypeManager->getNodeType(NodeTypeNameFactory::forFallback());
        if ($nodeType !== null && $nodeType->isOfType(NodeTypeNameFactory::NAME_SHORTCUT)) {
            $this->handleShortcutNode($controller, $previewAddress);
        }

        /** @var \Neos\Neos\View\FusionView $view */
        $view = ObjectAccess::getProperty($controller, 'view', true);
        $view->setOption('renderingModeName', RenderingMode::FRONTEND);
        $view->assignMultiple([
            'value' => $nodeInstance,
            'site' => $site,
        ]);

        return null;
    }

    /**
     * Replicates NodeController::handleShortcutNode() — which is protected on the
     * controller — so shortcut preview targets redirect to their resolved URI.
     *
     * @throws NodeNotFoundException
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    private function handleShortcutNode(ActionController $controller, NodeAddress $nodeAddress): void
    {
        try {
            $resolvedTarget = $this->nodeShortcutResolver->resolveShortcutTarget($nodeAddress);
        } catch (InvalidShortcutException $e) {
            throw new NodeNotFoundException(sprintf(
                'The shortcut node target of node %s could not be resolved: %s',
                $nodeAddress->toJson(),
                $e->getMessage()
            ), 1761223002, $e);
        }

        /** @var \Neos\Flow\Mvc\ActionRequest $request */
        $request = ObjectAccess::getProperty($controller, 'request', true);

        if ($resolvedTarget instanceof NodeAddress) {
            if ($nodeAddress->equals($resolvedTarget)) {
                return;
            }
            try {
                $resolvedUri = $this->nodeUriBuilderFactory->forActionRequest($request)->uriFor($nodeAddress);
            } catch (\Neos\Flow\Mvc\Exception\NoMatchingRouteException $e) {
                throw new NodeNotFoundException(sprintf(
                    'The shortcut node target of node %s could not be resolved: %s',
                    $nodeAddress->toJson(),
                    $e->getMessage()
                ), 1761223003, $e);
            }
        } else {
            $resolvedUri = $resolvedTarget;
        }

        // Call protected redirectToUri() via the controller — matches NodeController behavior.
        $redirectToUri = new \ReflectionMethod($controller, 'redirectToUri');
        $redirectToUri->invoke($controller, $resolvedUri, 0, $this->shortcutRedirectHttpStatusCode);
    }
}
