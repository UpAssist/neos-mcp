<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Controller;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

class McpBridgeController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @Flow\Inject
     * @var ContentRepositoryService
     */
    protected $crService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $previewTokensCache;

    /**
     * @Flow\InjectConfiguration(path="apiToken", package="UpAssist.Neos.Mcp")
     * @var string|null
     */
    protected $apiToken;

    /**
     * @Flow\InjectConfiguration(path="mcpWorkspaceName", package="UpAssist.Neos.Mcp")
     * @var string
     */
    protected $mcpWorkspaceName;

    /**
     * @Flow\InjectConfiguration(path="mcpWorkspaceTitle", package="UpAssist.Neos.Mcp")
     * @var string
     */
    protected $mcpWorkspaceTitle;

    /**
     * @Flow\InjectConfiguration(path="mcpWorkspaceDescription", package="UpAssist.Neos.Mcp")
     * @var string
     */
    protected $mcpWorkspaceDescription;

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    private function checkAuth(): void
    {
        $authHeader = $this->request->getHttpRequest()->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $authHeader);
        if (empty($this->apiToken) || !hash_equals((string) $this->apiToken, $token)) {
            $this->throwStatus(401, 'Unauthorized', json_encode(['error' => 'Unauthorized']));
        }
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** @Flow\SkipCsrfProtection */
    public function getSiteContextAction(): void
    {
        $this->checkAuth();

        $site = $this->crService->getDefaultSite();
        $siteNode = $this->crService->getSiteNode('live');
        $nodeTypes = $this->crService->getNodeTypes('all');
        $pages = $this->crService->collectDocumentNodes($siteNode, 'live', includeProperties: false);

        $this->view->assign('value', [
            'apiVersion' => 2,
            'siteName' => $site->getName(),
            'siteNodeName' => $site->getNodeName()->value,
            'siteNodeAggregateId' => $siteNode->aggregateId->value,
            'mcpWorkspace' => $this->mcpWorkspaceName,
            'nodeTypes' => $nodeTypes,
            'pages' => $pages,
            'workflowInstructions' => sprintf(
                'Always write content changes to the "%s" workspace. ' .
                'Use neos_list_pending_changes to review staged changes. ' .
                'Use neos_get_preview_url to get a preview link without logging in to Neos. ' .
                'Only call neos_publish_changes after the user explicitly confirms they want to go live.',
                $this->mcpWorkspaceName
            ),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function setupWorkspaceAction(): void
    {
        $this->checkAuth();
        $this->crService->ensureWorkspace(
            $this->mcpWorkspaceName,
            $this->mcpWorkspaceTitle,
            $this->mcpWorkspaceDescription
        );

        $this->view->assign('value', [
            'success' => true,
            'workspace' => [
                'name' => $this->mcpWorkspaceName,
                'title' => $this->mcpWorkspaceTitle,
            ],
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function listPagesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        $siteNode = $this->crService->getSiteNode($workspace);
        $this->view->assign('value', ['pages' => $this->crService->collectDocumentNodes($siteNode, $workspace)]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getPageContentAction(string $nodeAggregateId = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodeAggregateId === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId is required']));
        }

        $pageNode = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($pageNode === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
        }

        $subgraph = $this->crService->getSubgraph($workspace);

        // Collect all ContentCollections recursively (including nested ones, e.g. Columns → Column)
        $contentCollections = $this->crService->collectContentCollections($pageNode, $workspace);

        // Collect all content nodes (flat list of everything on the page)
        $contentNodes = [];
        foreach ($this->crService->collectContentNodes($pageNode, $workspace) as $node) {
            $contentNodes[] = $node;
        }

        $this->view->assign('value', [
            'page' => array_merge($this->crService->serializeNode($pageNode, $subgraph), [
                'title' => $pageNode->getProperty('title') ?? $pageNode->name?->value ?? '',
                'properties' => $this->crService->serializeNodeProperties($pageNode),
            ]),
            'contentCollections' => $contentCollections,
            'contentNodes' => $contentNodes,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getDocumentPropertiesAction(string $nodeAggregateId = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodeAggregateId === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId is required']));
        }

        $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
        }

        $subgraph = $this->crService->getSubgraph($workspace);
        $this->view->assign('value', array_merge($this->crService->serializeNode($node, $subgraph), [
            'title' => $node->getProperty('title') ?? $node->name?->value ?? '',
            'hidden' => $this->crService->isNodeHidden($node),
            'properties' => $this->crService->serializeNodeProperties($node),
        ]));
    }

    /** @Flow\SkipCsrfProtection */
    public function listNodeTypesAction(string $filter = 'content'): void
    {
        $this->checkAuth();
        $this->view->assign('value', ['nodeTypes' => $this->crService->getNodeTypes($filter)]);
    }

    /** @Flow\SkipCsrfProtection */
    public function listPendingChangesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        if (!$this->crService->workspaceExists($workspace)) {
            $this->view->assign('value', ['pendingChanges' => [], 'workspace' => $workspace, 'count' => 0]);
            return;
        }

        $changes = $this->crService->getPendingChanges($workspace);
        $this->view->assign('value', [
            'workspace' => $workspace,
            'count' => count($changes),
            'pendingChanges' => $changes,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getPreviewUrlAction(string $nodeAggregateId = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodeAggregateId === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId is required']));
        }

        $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+24 hours');

        $payload = json_encode([
            'workspace' => $workspace,
            'nodeAggregateId' => $nodeAggregateId,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $this->previewTokensCache->set($token, $payload);

        $baseUrl = rtrim((string) $this->request->getHttpRequest()->getUri()->withPath('')->withQuery('')->withFragment(''), '/');
        $frontendPath = $this->crService->buildFrontendPath($node, $workspace);
        $previewUrl = $baseUrl . $frontendPath . '?_mcpPreview=' . $token;

        $this->view->assign('value', [
            'previewUrl' => $previewUrl,
            'token' => $token,
            'workspace' => $workspace,
            'nodeAggregateId' => $nodeAggregateId,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function createContentNodeAction(
        string $parentNodeAggregateId = '',
        string $nodeType = '',
        array $properties = [],
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($parentNodeAggregateId === '' || $nodeType === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'parentNodeAggregateId and nodeType are required']));
        }

        try {
            $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

            $parentNode = $this->crService->findNodeById($parentNodeAggregateId, $workspace);
            if ($parentNode === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Parent node not found: ' . $parentNodeAggregateId]));
            }

            $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
            $nodeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

            $newNodeId = $this->crService->createNode(
                $workspace,
                $parentNodeAggregateId,
                $nodeType,
                $properties,
                $nodeName,
            );

            $newNode = $this->crService->findNodeById($newNodeId->value, $workspace);
            $subgraph = $this->crService->getSubgraph($workspace);

            $this->view->assign('value', [
                'success' => true,
                'node' => $newNode !== null ? $this->crService->serializeNode($newNode, $subgraph) : ['nodeAggregateId' => $newNodeId->value],
            ]);
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Internal Server Error', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function createDocumentNodeAction(
        string $parentNodeAggregateId = '',
        string $nodeType = '',
        array $properties = [],
        string $workspace = 'mcp',
        string $nodeName = '',
        string $insertBefore = '',
        string $insertAfter = ''
    ): void {
        $this->checkAuth();
        if ($parentNodeAggregateId === '' || $nodeType === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'parentNodeAggregateId and nodeType are required']));
        }

        try {
            $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

            $parentNode = $this->crService->findNodeById($parentNodeAggregateId, $workspace);
            if ($parentNode === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Parent node not found: ' . $parentNodeAggregateId]));
            }

            if ($nodeName !== '') {
                $safeName = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $nodeName));
            } else {
                $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
                $safeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            }

            // For insertBefore, pass the target as succeedingSibling
            $succeedingSiblingId = $insertBefore !== '' ? $insertBefore : null;

            $newNodeId = $this->crService->createNode(
                $workspace,
                $parentNodeAggregateId,
                $nodeType,
                $properties,
                $safeName,
                $succeedingSiblingId,
            );

            // For insertAfter, move the node after creation
            if ($insertAfter !== '') {
                // insertAfter means the new node should come after $insertAfter,
                // so $insertAfter becomes the preceding sibling
                $this->crService->moveNode(
                    $workspace,
                    $newNodeId->value,
                    newPrecedingSiblingId: $insertAfter,
                );
            }

            $newNode = $this->crService->findNodeById($newNodeId->value, $workspace);
            $subgraph = $this->crService->getSubgraph($workspace);

            $this->emitNodeMutated($newNode, 'New element added');

            $this->view->assign('value', [
                'success' => true,
                'node' => $newNode !== null ? $this->crService->serializeNode($newNode, $subgraph) : ['nodeAggregateId' => $newNodeId->value],
            ]);
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Internal Server Error', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function moveNodeAction(
        string $nodeAggregateId = '',
        string $insertBefore = '',
        string $insertAfter = '',
        string $newParentId = '',
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($nodeAggregateId === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId is required']));
        }
        $provided = array_filter([$insertBefore, $insertAfter, $newParentId], fn($v) => $v !== '');
        if (count($provided) !== 1) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'Provide exactly one of insertBefore, insertAfter, or newParentId']));
        }

        try {
            $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

            $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
            if ($node === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
            }

            if ($insertBefore !== '') {
                // insertBefore: the target becomes the succeeding sibling
                $this->crService->moveNode($workspace, $nodeAggregateId, newSucceedingSiblingId: $insertBefore);
            } elseif ($insertAfter !== '') {
                // insertAfter: the target becomes the preceding sibling
                $this->crService->moveNode($workspace, $nodeAggregateId, newPrecedingSiblingId: $insertAfter);
            } else {
                // Move into new parent
                $this->crService->moveNode($workspace, $nodeAggregateId, newParentId: $newParentId);
            }

            $this->emitNodeMutated($node, 'Element moved');

            $this->view->assign('value', [
                'success' => true,
                'nodeAggregateId' => $nodeAggregateId,
            ]);
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Internal Server Error', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function updateNodePropertyAction(
        string $nodeAggregateId = '',
        string $property = '',
        string $value = '',
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($nodeAggregateId === '' || $property === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId and property are required']));
        }

        try {
            $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

            $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
            if ($node === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
            }

            // System properties with dedicated handlers
            if ($property === '_hidden') {
                $hidden = is_string($value) ? strtolower($value) === 'true' || $value === '1' : (bool) $value;
                $this->crService->setNodeHidden($workspace, $nodeAggregateId, $hidden);
                $this->emitNodeMutated($node, "Property '_hidden' changed");
                $this->view->assign('value', [
                    'success' => true,
                    'nodeAggregateId' => $nodeAggregateId,
                    'property' => $property,
                    'newValue' => $hidden,
                ]);
                return;
            }

            // Check property type for references
            $cr = $this->crService->getContentRepository();
            $nodeType = $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
            $propertyType = $nodeType?->getPropertyType($property);

            // Handle reference types via SetNodeReferences command
            if ($propertyType === 'reference') {
                $targetIds = $value !== '' ? [$value] : [];
                $this->crService->setNodeReferences($workspace, $nodeAggregateId, $property, $targetIds);
                $this->emitNodeMutated($node, "Property '{$property}' changed");
                $this->view->assign('value', [
                    'success' => true,
                    'nodeAggregateId' => $nodeAggregateId,
                    'property' => $property,
                    'newValue' => $value,
                ]);
                return;
            }

            if ($propertyType === 'references') {
                $targetIds = $this->crService->resolveReferenceIdentifiers($value, $workspace);
                $this->crService->setNodeReferences($workspace, $nodeAggregateId, $property, $targetIds);
                $this->emitNodeMutated($node, "Property '{$property}' changed");
                $this->view->assign('value', [
                    'success' => true,
                    'nodeAggregateId' => $nodeAggregateId,
                    'property' => $property,
                    'newValue' => $targetIds,
                ]);
                return;
            }

            // Regular property — resolve value type and set
            $resolvedValue = $this->crService->resolvePropertyValue($node, $property, $value);
            $this->crService->setNodeProperties($workspace, $nodeAggregateId, [$property => $resolvedValue]);

            $this->emitNodeMutated($node, "Property '{$property}' changed");

            $displayValue = $resolvedValue instanceof \Neos\Media\Domain\Model\AssetInterface
                ? 'asset,' . $this->persistenceManager->getIdentifierByObject($resolvedValue)
                : $value;

            $this->view->assign('value', [
                'success' => true,
                'nodeAggregateId' => $nodeAggregateId,
                'property' => $property,
                'newValue' => $displayValue,
            ]);
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Internal Server Error', json_encode(['error' => $e->getMessage()]));
        }
    }

    /** @Flow\SkipCsrfProtection */
    public function deleteNodeAction(string $nodeAggregateId = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        if ($nodeAggregateId === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodeAggregateId is required']));
        }

        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

        $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodeAggregateId]));
        }

        $this->emitNodeMutated($node, 'Element removed');
        $this->crService->removeNode($workspace, $nodeAggregateId);

        $this->view->assign('value', [
            'success' => true,
            'removedNodeAggregateId' => $nodeAggregateId,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function publishChangesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        if (!$this->crService->workspaceExists($workspace)) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Workspace not found: ' . $workspace]));
        }

        $count = $this->crService->publishWorkspace($workspace);

        $this->view->assign('value', [
            'success' => true,
            'publishedNodes' => $count,
            'workspace' => $workspace,
            'targetWorkspace' => 'live',
        ]);
    }

    // -------------------------------------------------------------------------
    // Assets / Media (unchanged — Neos Media API is stable)
    // -------------------------------------------------------------------------

    /** @Flow\SkipCsrfProtection */
    public function listAssetsAction(string $mediaType = 'image', string $tag = '', int $limit = 50, int $offset = 0): void
    {
        $this->checkAuth();

        $query = $this->assetRepository->createQuery();
        $constraints = [];

        if ($mediaType !== '') {
            $constraints[] = $query->like('resource.mediaType', $mediaType . '/%');
        }

        if (!empty($constraints)) {
            $query->matching($query->logicalAnd($constraints));
        }

        $query->setOrderings(['lastModified' => \Neos\Flow\Persistence\QueryInterface::ORDER_DESCENDING]);
        $query->setLimit($limit);
        $query->setOffset($offset);

        $assets = $query->execute();
        $total = $query->count();

        $result = [];
        foreach ($assets as $asset) {
            $resource = $asset->getResource();
            $tags = [];
            foreach ($asset->getTags() as $assetTag) {
                $tags[] = $assetTag->getLabel();
            }

            $collections = [];
            foreach ($asset->getAssetCollections() as $collection) {
                $collections[] = $collection->getTitle();
            }

            $result[] = [
                'identifier' => $this->persistenceManager->getIdentifierByObject($asset),
                'title' => $asset->getTitle() ?: '',
                'caption' => $asset->getCaption() ?: '',
                'filename' => $resource ? $resource->getFilename() : '',
                'mediaType' => $resource ? $resource->getMediaType() : '',
                'fileSize' => $resource ? $resource->getFileSize() : 0,
                'tags' => $tags,
                'collections' => $collections,
                'lastModified' => $asset->getLastModified() ? $asset->getLastModified()->format('c') : null,
            ];
        }

        if ($tag !== '') {
            $result = array_values(array_filter($result, function ($item) use ($tag) {
                return in_array($tag, $item['tags'], true);
            }));
        }

        $this->view->assign('value', [
            'assets' => $result,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'mediaTypeFilter' => $mediaType,
            'tagFilter' => $tag,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function listAssetTagsAction(): void
    {
        $this->checkAuth();

        $tags = $this->tagRepository->findAll();
        $result = [];
        foreach ($tags as $tag) {
            $result[] = [
                'identifier' => $this->persistenceManager->getIdentifierByObject($tag),
                'label' => $tag->getLabel(),
            ];
        }

        $this->view->assign('value', ['tags' => $result]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requireWorkspace(string $workspaceName): void
    {
        if ($workspaceName !== 'live' && !$this->crService->workspaceExists($workspaceName)) {
            $this->throwStatus(400, 'Workspace not found', json_encode(['error' => "Workspace '{$workspaceName}' does not exist. Call setupWorkspace first."]));
        }
    }

    // -------------------------------------------------------------------------
    // Signals
    // -------------------------------------------------------------------------

    /**
     * Signal emitted after a content node is mutated via MCP.
     *
     * @Flow\Signal
     */
    protected function emitNodeMutated(?Node $node, string $changeDescription): void
    {
    }
}
