<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Service\PublishingService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Cache\Frontend\StringFrontend;

class McpBridgeController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

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
    // Helpers
    // -------------------------------------------------------------------------

    private function getSiteNodePath(): string
    {
        $site = $this->siteRepository->findDefault();
        if ($site === null) {
            $this->throwStatus(500, 'No site found', json_encode(['error' => 'No default site found']));
        }
        return '/sites/' . $site->getNodeName();
    }

    private function createContext(string $workspace = 'live'): \Neos\ContentRepository\Domain\Service\Context
    {
        return $this->contextFactory->create([
            'workspaceName' => $workspace,
            'invisibleContentShown' => true,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false,
        ]);
    }

    private function requireWorkspace(string $workspaceName): void
    {
        if ($workspaceName !== 'live' && $this->workspaceRepository->findByIdentifier($workspaceName) === null) {
            $this->throwStatus(400, 'Workspace not found', json_encode(['error' => "Workspace '{$workspaceName}' does not exist. Call setupWorkspace first."]));
        }
    }

    private function ensureMcpWorkspace(): Workspace
    {
        $workspace = $this->workspaceRepository->findByIdentifier($this->mcpWorkspaceName);
        if ($workspace === null) {
            $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
            $workspace = new Workspace($this->mcpWorkspaceName, $liveWorkspace);
            $workspace->setTitle($this->mcpWorkspaceTitle);
            $workspace->setDescription($this->mcpWorkspaceDescription);
            $this->workspaceRepository->add($workspace);
            $this->persistenceManager->persistAll();
        }
        return $workspace;
    }

    private function serializeNodeProperties(NodeInterface $node): array
    {
        $nodeType = $node->getNodeType();
        $result = [];
        foreach (array_keys($nodeType->getProperties()) as $propertyName) {
            if (str_starts_with($propertyName, '_')) {
                continue;
            }
            $value = $node->getProperty($propertyName);
            if ($value === null) {
                $result[$propertyName] = null;
                continue;
            }
            $type = $nodeType->getPropertyType($propertyName);
            if ($type === 'references' && is_array($value)) {
                $refs = [];
                foreach ($value as $refNode) {
                    if ($refNode instanceof NodeInterface) {
                        $refs[] = $refNode->getIdentifier();
                    } elseif (is_string($refNode)) {
                        $refs[] = $refNode;
                    }
                }
                $result[$propertyName] = $refs;
            } elseif ($type === 'reference') {
                if ($value instanceof NodeInterface) {
                    $result[$propertyName] = $value->getIdentifier();
                } elseif (is_string($value)) {
                    $result[$propertyName] = $value;
                } else {
                    $result[$propertyName] = null;
                }
            } elseif (str_contains($type, 'Image') || str_contains($type, 'Asset') || str_contains($type, 'Media')) {
                $result[$propertyName] = [
                    '__type' => 'asset',
                    'identifier' => $this->persistenceManager->getIdentifierByObject($value),
                ];
            } elseif ($value instanceof NodeInterface) {
                $result[$propertyName] = $value->getContextPath();
            } elseif (is_object($value)) {
                try {
                    $result[$propertyName] = ['__type' => get_class($value), 'identifier' => $this->persistenceManager->getIdentifierByObject($value)];
                } catch (\Exception $e) {
                    $result[$propertyName] = null;
                }
            } else {
                $result[$propertyName] = $value;
            }
        }
        return $result;
    }

    private function serializeNode(NodeInterface $node): array
    {
        return [
            'identifier' => $node->getIdentifier(),
            'contextPath' => $node->getContextPath(),
            'path' => $node->getPath(),
            'nodeType' => $node->getNodeType()->getName(),
            'name' => $node->getName(),
        ];
    }

    private function collectDocumentNodes(NodeInterface $node, int $depth = 0): array
    {
        $pages = [];
        $pages[] = array_merge($this->serializeNode($node), [
            'title' => $node->getProperty('title') ?? $node->getName(),
            'hidden' => $node->isHidden(),
            'depth' => $depth,
            'properties' => $this->serializeNodeProperties($node),
        ]);
        foreach ($node->getChildNodes('Neos.Neos:Document') as $child) {
            foreach ($this->collectDocumentNodes($child, $depth + 1) as $page) {
                $pages[] = $page;
            }
        }
        return $pages;
    }

    private function collectContentNodes(NodeInterface $node): array
    {
        $nodes = [];
        foreach ($node->getChildNodes() as $child) {
            if ($child->getNodeType()->isOfType('Neos.Neos:Document')) {
                continue;
            }
            $nodes[] = array_merge($this->serializeNode($child), [
                'properties' => $this->serializeNodeProperties($child),
            ]);
            foreach ($this->collectContentNodes($child) as $nested) {
                $nodes[] = $nested;
            }
        }
        return $nodes;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /** @Flow\SkipCsrfProtection */
    public function getSiteContextAction(): void
    {
        $this->checkAuth();

        $site = $this->siteRepository->findDefault();
        $siteNodePath = '/sites/' . $site->getNodeName();
        $context = $this->createContext('live');
        $siteNode = $context->getNode($siteNodePath);

        $nodeTypes = [];
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $nodeType) {
            if (!$nodeType->isOfType('Neos.Neos:Content') && !$nodeType->isOfType('Neos.Neos:Document')) {
                continue;
            }
            $properties = [];
            foreach ($nodeType->getProperties() as $name => $config) {
                if (str_starts_with($name, '_')) {
                    continue;
                }
                $properties[$name] = [
                    'type' => $nodeType->getPropertyType($name),
                    'defaultValue' => $nodeType->getDefaultValuesForProperties()[$name] ?? null,
                    'label' => $config['ui']['label'] ?? $name,
                ];
            }
            $nodeTypes[] = [
                'name' => $nodeType->getName(),
                'isContent' => $nodeType->isOfType('Neos.Neos:Content'),
                'isDocument' => $nodeType->isOfType('Neos.Neos:Document'),
                'properties' => $properties,
            ];
        }

        $pages = $siteNode ? $this->collectDocumentNodes($siteNode) : [];

        $this->view->assign('value', [
            'siteName' => $site->getName(),
            'siteNodeName' => $site->getNodeName(),
            'siteNodePath' => $siteNodePath,
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
        $workspace = $this->ensureMcpWorkspace();
        $this->view->assign('value', [
            'success' => true,
            'workspace' => [
                'name' => $workspace->getName(),
                'title' => $workspace->getTitle(),
            ],
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function listPagesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        $context = $this->createContext($workspace);
        $siteNode = $context->getNode($this->getSiteNodePath());
        if ($siteNode === null) {
            $this->view->assign('value', ['pages' => []]);
            return;
        }
        $this->view->assign('value', ['pages' => $this->collectDocumentNodes($siteNode)]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getPageContentAction(string $nodePath = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodePath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodePath is required']));
        }
        $context = $this->createContext($workspace);
        $pageNode = $context->getNode($nodePath);
        if ($pageNode === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodePath]));
        }
        $contentNodes = [];
        foreach ($pageNode->getChildNodes() as $child) {
            if ($child->getNodeType()->isOfType('Neos.Neos:ContentCollection')) {
                foreach ($this->collectContentNodes($child) as $node) {
                    $contentNodes[] = $node;
                }
            }
        }
        $this->view->assign('value', [
            'page' => array_merge($this->serializeNode($pageNode), [
                'title' => $pageNode->getProperty('title') ?? $pageNode->getName(),
                'properties' => $this->serializeNodeProperties($pageNode),
            ]),
            'contentNodes' => $contentNodes,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getDocumentPropertiesAction(string $nodePath = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodePath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodePath is required']));
        }
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);
        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodePath]));
        }
        $this->view->assign('value', array_merge($this->serializeNode($node), [
            'title' => $node->getProperty('title') ?? $node->getName(),
            'hidden' => $node->isHidden(),
            'properties' => $this->serializeNodeProperties($node),
        ]));
    }

    /** @Flow\SkipCsrfProtection */
    public function listNodeTypesAction(string $filter = 'content'): void
    {
        $this->checkAuth();
        $nodeTypes = [];
        foreach ($this->nodeTypeManager->getNodeTypes(false) as $nodeType) {
            if ($filter === 'content' && !$nodeType->isOfType('Neos.Neos:Content')) {
                continue;
            }
            if ($filter === 'document' && !$nodeType->isOfType('Neos.Neos:Document')) {
                continue;
            }
            $properties = [];
            foreach ($nodeType->getProperties() as $name => $config) {
                if (str_starts_with($name, '_')) {
                    continue;
                }
                $properties[$name] = [
                    'type' => $nodeType->getPropertyType($name),
                    'defaultValue' => $nodeType->getDefaultValuesForProperties()[$name] ?? null,
                    'label' => $config['ui']['label'] ?? $name,
                ];
            }
            $nodeTypes[] = [
                'name' => $nodeType->getName(),
                'properties' => $properties,
            ];
        }
        $this->view->assign('value', ['nodeTypes' => $nodeTypes]);
    }

    /** @Flow\SkipCsrfProtection */
    public function listPendingChangesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $workspaceObject = $this->workspaceRepository->findByIdentifier($workspace);
        if ($workspaceObject === null) {
            $this->view->assign('value', ['pendingChanges' => [], 'workspace' => $workspace]);
            return;
        }
        $unpublished = $this->publishingService->getUnpublishedNodes($workspaceObject);
        $changes = [];
        foreach ($unpublished as $node) {
            $shadowNode = null;
            try {
                $liveContext = $this->createContext('live');
                $shadowNode = $liveContext->getNodeByIdentifier($node->getIdentifier());
            } catch (\Exception $e) {
                // node is new
            }
            $changes[] = [
                'identifier' => $node->getIdentifier(),
                'contextPath' => $node->getContextPath(),
                'path' => $node->getPath(),
                'nodeType' => $node->getNodeType()->getName(),
                'changeType' => $shadowNode === null ? 'added' : ($node->isRemoved() ? 'removed' : 'modified'),
            ];
        }
        $this->view->assign('value', [
            'workspace' => $workspace,
            'count' => count($changes),
            'pendingChanges' => $changes,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getPreviewUrlAction(string $nodePath = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $this->requireWorkspace($workspace);
        if ($nodePath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'nodePath is required']));
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+24 hours');

        $payload = json_encode([
            'workspace' => $workspace,
            'nodePath' => $nodePath,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $this->previewTokensCache->set($token, $payload);

        $baseUrl = rtrim((string) $this->request->getHttpRequest()->getUri()->withPath('')->withQuery('')->withFragment(''), '/');

        // Build the frontend URL using uriPathSegment properties (not node names)
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);
        $siteNodePath = $this->getSiteNodePath();
        $segments = [];
        $current = $node;
        while ($current !== null && $current->getPath() !== $siteNodePath) {
            $segment = $current->getProperty('uriPathSegment');
            if ($segment !== null && $segment !== '') {
                array_unshift($segments, $segment);
            }
            $current = $current->getParent();
        }
        $frontendPath = '/' . implode('/', $segments);

        $previewUrl = $baseUrl . $frontendPath . '?_mcpPreview=' . $token;

        $this->view->assign('value', [
            'previewUrl' => $previewUrl,
            'token' => $token,
            'workspace' => $workspace,
            'nodePath' => $nodePath,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function createContentNodeAction(
        string $parentPath = '',
        string $nodeType = '',
        array $properties = [],
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($parentPath === '' || $nodeType === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'parentPath and nodeType are required']));
        }

        $this->ensureMcpWorkspace();
        $context = $this->createContext($workspace);
        $parentNode = $context->getNode($parentPath);

        if ($parentNode === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Parent node not found: ' . $parentPath]));
        }

        $nodeTypeObject = $this->nodeTypeManager->getNodeType($nodeType);
        $template = new NodeTemplate();
        $template->setNodeType($nodeTypeObject);

        $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
        $nodeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $template->setName($nodeName);

        foreach ($properties as $key => $value) {
            $template->setProperty($key, $value);
        }

        $newNode = $parentNode->createNodeFromTemplate($template);
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'node' => $this->serializeNode($newNode),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function createDocumentNodeAction(
        string $parentPath = '',
        string $nodeType = '',
        array $properties = [],
        string $workspace = 'mcp',
        string $nodeName = '',
        string $insertBefore = '',
        string $insertAfter = ''
    ): void {
        $this->checkAuth();
        if ($parentPath === '' || $nodeType === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'parentPath and nodeType are required']));
        }

        $this->ensureMcpWorkspace();
        $context = $this->createContext($workspace);
        $parentNode = $context->getNode($parentPath);

        if ($parentNode === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Parent node not found: ' . $parentPath]));
        }

        $nodeTypeObject = $this->nodeTypeManager->getNodeType($nodeType);
        $template = new NodeTemplate();
        $template->setNodeType($nodeTypeObject);

        if ($nodeName !== '') {
            $safeName = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $nodeName));
        } else {
            $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
            $safeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $template->setName($safeName);

        foreach ($properties as $key => $value) {
            $template->setProperty($key, $value);
        }

        $newNode = $parentNode->createNodeFromTemplate($template);

        if ($insertBefore !== '') {
            $siblingPath = preg_replace('/@.*$/', '', $insertBefore);
            $sibling = $context->getNode($siblingPath);
            if ($sibling === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertBefore node not found: ' . $insertBefore]));
            }
            $newNode->moveBefore($sibling);
        } elseif ($insertAfter !== '') {
            $siblingPath = preg_replace('/@.*$/', '', $insertAfter);
            $sibling = $context->getNode($siblingPath);
            if ($sibling === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertAfter node not found: ' . $insertAfter]));
            }
            $newNode->moveAfter($sibling);
        }

        $this->emitNodeMutated($newNode, 'New element added');
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'node' => $this->serializeNode($newNode),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function moveNodeAction(
        string $contextPath = '',
        string $insertBefore = '',
        string $insertAfter = '',
        string $newParentPath = '',
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($contextPath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'contextPath is required']));
        }
        $provided = array_filter([$insertBefore, $insertAfter, $newParentPath], fn($v) => $v !== '');
        if (count($provided) !== 1) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'Provide exactly one of insertBefore, insertAfter, or newParentPath']));
        }

        $this->ensureMcpWorkspace();
        $nodePath = preg_replace('/@.*$/', '', $contextPath);
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);

        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodePath]));
        }

        if ($insertBefore !== '') {
            $refPath = preg_replace('/@.*$/', '', $insertBefore);
            $ref = $context->getNode($refPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertBefore node not found: ' . $insertBefore]));
            }
            $node->moveBefore($ref);
        } elseif ($insertAfter !== '') {
            $refPath = preg_replace('/@.*$/', '', $insertAfter);
            $ref = $context->getNode($refPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertAfter node not found: ' . $insertAfter]));
            }
            $node->moveAfter($ref);
        } else {
            $ref = $context->getNode($newParentPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'newParentPath node not found: ' . $newParentPath]));
            }
            $node->moveInto($ref);
        }

        $this->emitNodeMutated($node, 'Element moved');
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'contextPath' => $node->getContextPath(),
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function updateNodePropertyAction(
        string $contextPath = '',
        string $property = '',
        string $value = '',
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($contextPath === '' || $property === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'contextPath and property are required']));
        }

        $this->ensureMcpWorkspace();
        // Extract node path from context path (strip @workspace)
        $nodePath = preg_replace('/@.*$/', '', $contextPath);
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);

        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodePath]));
        }

        // Resolve asset references: if the property type is an Image/Asset and the value looks like an asset identifier, look it up
        $resolvedValue = $value;
        $nodeType = $node->getNodeType();
        $propertyType = $nodeType->getPropertyType($property);
        if ($propertyType !== null && (str_contains($propertyType, 'Image') || str_contains($propertyType, 'Asset'))) {
            $assetIdentifier = $value;
            // Support both plain UUID and JSON object format {"__type":"asset","identifier":"uuid"}
            if (is_string($value) && str_starts_with(trim($value), '{')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded) && isset($decoded['identifier'])) {
                    $assetIdentifier = $decoded['identifier'];
                }
            }
            $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
            if ($asset !== null) {
                $resolvedValue = $asset;
            } else {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Asset not found: ' . $assetIdentifier]));
            }
        }

        // Handle boolean values passed as strings
        if (is_string($resolvedValue) && in_array(strtolower($resolvedValue), ['true', 'false'], true)) {
            $resolvedValue = strtolower($resolvedValue) === 'true';
        }

        // Resolve reference: store as node identifier string (Neos resolves to NodeInterface on read)
        if ($propertyType === 'reference' && is_string($resolvedValue) && $resolvedValue !== '') {
            $referencedNode = $context->getNodeByIdentifier($resolvedValue);
            if ($referencedNode === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Referenced node not found: ' . $resolvedValue]));
            }
            // Keep as string identifier — Neos stores references as identifier strings
        }

        // Resolve references: JSON array of node identifiers → validated array of identifier strings
        // Neos stores references as identifier strings in NodeData properties; it resolves to NodeInterface on read
        if ($propertyType === 'references') {
            $identifiers = is_string($resolvedValue) ? json_decode($resolvedValue, true) : $resolvedValue;
            if (is_array($identifiers)) {
                $validated = [];
                foreach ($identifiers as $identifier) {
                    $refNode = $context->getNodeByIdentifier($identifier);
                    if ($refNode !== null) {
                        $validated[] = $identifier;
                    }
                }
                $resolvedValue = array_values($validated);
            }
        }

        // Resolve DateTime: parse date string → \DateTime object
        if ($propertyType === 'DateTime' && is_string($resolvedValue) && $resolvedValue !== '') {
            try {
                $resolvedValue = new \DateTime($resolvedValue);
            } catch (\Exception $e) {
                $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'Invalid date format: ' . $resolvedValue]));
            }
        }

        // Resolve array: JSON string → PHP array
        if ($propertyType === 'array' && is_string($resolvedValue)) {
            $decoded = json_decode($resolvedValue, true);
            if (is_array($decoded)) {
                $resolvedValue = $decoded;
            }
        }

        // System properties (prefixed with _) need dedicated setters
        if ($property === '_hidden') {
            $node->setHidden((bool)$resolvedValue);
        } elseif ($property === '_hiddenInIndex') {
            $node->setHiddenInIndex((bool)$resolvedValue);
        } else {
            $node->setProperty($property, $resolvedValue);
        }
        $this->emitNodeMutated($node, "Property '{$property}' changed");
        $this->persistenceManager->persistAll();

        $displayValue = $resolvedValue instanceof \Neos\Media\Domain\Model\AssetInterface
            ? 'asset,' . $this->persistenceManager->getIdentifierByObject($resolvedValue)
            : $value;

        $this->view->assign('value', [
            'success' => true,
            'contextPath' => $node->getContextPath(),
            'property' => $property,
            'newValue' => $displayValue,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function deleteNodeAction(string $contextPath = '', string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        if ($contextPath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'contextPath is required']));
        }

        $this->ensureMcpWorkspace();
        $nodePath = preg_replace('/@.*$/', '', $contextPath);
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);

        if ($node === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Node not found: ' . $nodePath]));
        }

        $this->emitNodeMutated($node, 'Element removed');
        $node->remove();
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'removedContextPath' => $contextPath,
        ]);
    }

    /** @Flow\SkipCsrfProtection */
    public function publishChangesAction(string $workspace = 'mcp'): void
    {
        $this->checkAuth();
        $workspaceObject = $this->workspaceRepository->findByIdentifier($workspace);
        if ($workspaceObject === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => 'Workspace not found: ' . $workspace]));
        }

        $unpublished = $this->publishingService->getUnpublishedNodes($workspaceObject);
        $count = count($unpublished);

        $this->publishingService->publishNodes($unpublished);
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'publishedNodes' => $count,
            'workspace' => $workspace,
            'targetWorkspace' => 'live',
        ]);
    }

    // -------------------------------------------------------------------------
    // Assets / Media
    // -------------------------------------------------------------------------

    /**
     * List assets from the Neos Media Manager.
     * Supports filtering by mediaType prefix (e.g. "image") and tag name.
     * Returns identifier, title, filename, mediaType, fileSize, and tags.
     *
     * @Flow\SkipCsrfProtection
     */
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

        // Filter by tag name if specified (post-query filter since tag is a relation)
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

    /**
     * List all available tags in the Media Manager.
     *
     * @Flow\SkipCsrfProtection
     */
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
    // Signals
    // -------------------------------------------------------------------------

    /**
     * Signal emitted after a content node is mutated via MCP.
     *
     * @Flow\Signal
     */
    protected function emitNodeMutated(NodeInterface $node, string $changeDescription): void
    {
    }
}
