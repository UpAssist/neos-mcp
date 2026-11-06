<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Service\PublishingService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Cache\Frontend\StringFrontend;
use UpAssist\Neos\Mcp\Service\DimensionSelection;
use UpAssist\Neos\Mcp\Service\InvalidDimensionsException;
use UpAssist\Neos\Mcp\Service\NodePropertyResolutionException;
use UpAssist\Neos\Mcp\Service\SiteService;

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
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

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
     * @var SiteService
     */
    protected $siteService;

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
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var AssetModelMappingStrategyInterface
     */
    protected $assetModelMappingStrategy;

    /**
     * @Flow\Inject
     * @var \UpAssist\Neos\Mcp\Service\NodePropertyResolver
     */
    protected $nodePropertyResolver;

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

        // Fallback: nginx/PHP-FPM strips the Authorization header before PHP sees it.
        // Read token from X-MCP-Token header which nginx passes through unchanged.
        if (empty($token)) {
            $token = $_SERVER['HTTP_X_MCP_TOKEN'] ?? '';
        }

        if (empty($this->apiToken) || !hash_equals((string) $this->apiToken, $token)) {
            $this->throwStatus(401, 'Unauthorized', json_encode(['error' => 'Unauthorized']));
        }
    }

    /**
     * Content dimensions requested for this request as dimension name => preset name,
     * or null when the "dimensions" argument was not given (the default dimensions apply).
     *
     * @var array<string, string>|null
     */
    private ?array $requestedDimensions = null;

    /**
     * Every endpoint accepts an optional "site" argument (site node name) that selects
     * the site for site-scoped operations: site context, page tree, pending changes and
     * publishing. Without it the default site is used.
     */
    protected function initializeAction(): void
    {
        parent::initializeAction();
        $this->siteService->selectSite(null);
        $this->requestedDimensions = null;

        if ($this->request->hasArgument('dimensions')) {
            $this->checkAuth();
            $this->requestedDimensions = $this->parseDimensionsArgument($this->request->getArgument('dimensions'));
        }

        if (!$this->request->hasArgument('site')) {
            return;
        }
        $site = $this->request->getArgument('site');
        if (!is_string($site) || trim($site) === '') {
            return;
        }

        $this->checkAuth();
        try {
            $this->siteService->selectSite($site);
        } catch (\RuntimeException $e) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => $e->getMessage()]));
        }
    }

    private function dimensionSelection(): DimensionSelection
    {
        return new DimensionSelection($this->contentDimensionPresetSource->getAllPresets());
    }

    /**
     * Resolves the optional "dimensions" argument (an array, or a JSON object string such as
     * {"language":"nl"}) to dimension name => preset name. Dimensions that are left out get their
     * default preset. Returns null when nothing was requested.
     *
     * @return array<string, string>|null
     */
    private function parseDimensionsArgument(mixed $raw): ?array
    {
        try {
            return $this->dimensionSelection()->parse($raw);
        } catch (InvalidDimensionsException $e) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Context dimensions (value list including fallbacks) and target dimensions (the single
     * value new variants are created in) for the requested presets.
     *
     * @return array{dimensions: array<string, array<int, string>>, targetDimensions: array<string, string>}
     */
    private function contextDimensionConfiguration(): array
    {
        return $this->dimensionSelection()->contextConfiguration($this->requestedDimensions ?? []);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function serializeAsset(\Neos\Media\Domain\Model\AssetInterface $asset): array
    {
        $resource = $asset->getResource();
        $tags = [];
        foreach ($asset->getTags() as $assetTag) {
            $tags[] = $assetTag->getLabel();
        }
        $collections = [];
        foreach ($asset->getAssetCollections() as $collection) {
            $collections[] = $collection->getTitle();
        }

        return [
            'identifier' => $this->persistenceManager->getIdentifierByObject($asset),
            'title' => $asset->getTitle() ?: '',
            'caption' => $asset->getCaption() ?: '',
            'filename' => $resource ? $resource->getFilename() : '',
            'mediaType' => $resource ? $resource->getMediaType() : '',
            'fileSize' => $resource ? (int) $resource->getFileSize() : 0,
            'assetType' => (new \ReflectionClass($asset))->getShortName(),
            'publicUri' => $resource ? $this->resourceManager->getPublicPersistentResourceUri($resource) : null,
            'tags' => $tags,
            'collections' => $collections,
        ];
    }

    private function getSiteNodePath(): string
    {
        $site = $this->siteService->getCurrentSite();
        if ($site === null) {
            $this->throwStatus(500, 'No site found', json_encode(['error' => 'No default site found']));
        }
        return '/sites/' . $site->getNodeName();
    }

    /**
     * Unpublished nodes of a workspace, optionally limited to one site (attributed via the
     * /sites/<node name> prefix of the node path).
     *
     * @return NodeInterface[]
     */
    private function unpublishedNodesOfSite(Workspace $workspace, ?\Neos\Neos\Domain\Model\Site $site): array
    {
        $unpublished = $this->publishingService->getUnpublishedNodes($workspace);
        if ($this->requestedDimensions !== null) {
            $unpublished = array_values(array_filter(
                $unpublished,
                fn (NodeInterface $node): bool => $this->nodeStoredInRequestedDimensions($node)
            ));
        }
        if ($site === null) {
            return $unpublished;
        }
        $siteNodeName = (string)$site->getNodeName();
        return array_values(array_filter(
            $unpublished,
            fn (NodeInterface $node): bool => $this->siteService->siteNodeNameForPath($node->getPath()) === $siteNodeName
        ));
    }

    /**
     * Whether the node is stored in exactly the requested dimensions (not merely visible
     * there through a fallback). True when no dimensions were requested.
     */
    private function nodeStoredInRequestedDimensions(NodeInterface $node): bool
    {
        if ($this->requestedDimensions === null) {
            return true;
        }
        return $this->dimensionSelection()->isStoredIn($this->nodeDimensionInfo($node)['dimensions'], $this->requestedDimensions);
    }

    /**
     * The URI path segment of the requested dimensions (e.g. "uk", or "uk_b2b" for several
     * dimensions), or '' when none was requested or the presets have no URI segment.
     */
    private function requestedDimensionsUriSegment(): string
    {
        if ($this->requestedDimensions === null) {
            return '';
        }
        return $this->dimensionSelection()->uriSegment($this->requestedDimensions);
    }

    private function createContext(string $workspace = 'live'): \Neos\ContentRepository\Domain\Service\Context
    {
        $configuration = [
            'workspaceName' => $workspace,
            'invisibleContentShown' => true,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false,
        ];
        if ($this->requestedDimensions !== null) {
            $configuration = array_merge($configuration, $this->contextDimensionConfiguration());
        }
        return $this->contextFactory->create($configuration);
    }

    /**
     * Context for an explicit dimension => preset map (independent of the request's dimensions).
     */
    private function createContextForDimensions(string $workspace, array $presets): \Neos\ContentRepository\Domain\Service\Context
    {
        $configuration = $this->dimensionSelection()->contextConfiguration($presets);
        return $this->contextFactory->create([
            'workspaceName' => $workspace,
            'invisibleContentShown' => true,
            'removedContentShown' => false,
            'inaccessibleContentShown' => false,
            'dimensions' => $configuration['dimensions'],
            'targetDimensions' => $configuration['targetDimensions'],
        ]);
    }

    /**
     * Dimension values the node is actually stored in (dimension => first value) and whether
     * the node is only visible here through a dimension fallback.
     *
     * @return array{dimensions: array<string, string>, fallback: bool}
     */
    private function nodeDimensionInfo(NodeInterface $node): array
    {
        $stored = [];
        foreach ($node->getNodeData()->getDimensionValues() as $dimension => $values) {
            $stored[$dimension] = (string)(is_array($values) ? reset($values) : $values);
        }
        $fallback = false;
        $target = $node->getContext()->getTargetDimensions();
        foreach ($stored as $dimension => $value) {
            if (isset($target[$dimension]) && $target[$dimension] !== $value) {
                $fallback = true;
            }
        }
        return ['dimensions' => $stored, 'fallback' => $fallback];
    }

    /**
     * When dimensions were requested explicitly, write operations must act on a node that is
     * stored in that dimension. A node that is only visible through a fallback would otherwise
     * be copied implicitly (or, for removal, silently left alone).
     */
    private function requireNodeInRequestedDimensions(NodeInterface $node, string $role = 'Node'): void
    {
        if ($this->requestedDimensions === null) {
            return;
        }
        $info = $this->nodeDimensionInfo($node);
        if (!$info['fallback']) {
            return;
        }
        $this->throwStatus(409, 'Conflict', json_encode([
            'error' => sprintf(
                '%s %s only exists in %s and is visible in %s through a dimension fallback. '
                . 'Create a variant first with createNodeVariant.',
                $role,
                $node->getPath(),
                json_encode($info['dimensions']),
                json_encode($this->requestedDimensions)
            ),
            'dimensions' => $info['dimensions'],
            'requestedDimensions' => $this->requestedDimensions,
        ]));
    }

    /**
     * Neos 8 does not check the node type constraints in createNodeFromTemplate() and the
     * move methods, the Neos UI does it before. The bridge has to do the same, otherwise
     * nodes end up where an editor could never put them (e.g. a page type that is only
     * allowed inside of a certain collection).
     */
    private function requireNodeTypeAllowedIn(NodeInterface $parentNode, NodeType $nodeType): void
    {
        if ($parentNode->isNodeTypeAllowedAsChildNode($nodeType)) {
            return;
        }
        $this->throwStatus(422, 'Unprocessable Entity', json_encode([
            'error' => sprintf(
                'Node type %s is not allowed in %s (%s), see the constraints of the node types.',
                $nodeType->getName(),
                $parentNode->getPath(),
                $parentNode->getNodeType()->getName()
            ),
        ]));
    }

    /**
     * Reordering inside of the same parent stays possible, even for nodes that were created
     * before a constraint was added.
     */
    private function requireNodeTypeAllowedInNewParent(NodeInterface $node, NodeInterface $newParentNode): void
    {
        if ($newParentNode->getPath() === $node->getParentPath()) {
            return;
        }
        $this->requireNodeTypeAllowedIn($newParentNode, $node->getNodeType());
    }

    /**
     * @return array<string, string> default preset per dimension
     */
    private function defaultDimensions(): array
    {
        return $this->dimensionSelection()->defaults();
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
                $result[$propertyName] = is_object($value)
                    ? ['__type' => 'asset', 'identifier' => $this->persistenceManager->getIdentifierByObject($value)]
                    : null;
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
        return array_merge([
            'identifier' => $node->getIdentifier(),
            'contextPath' => $node->getContextPath(),
            'path' => $node->getPath(),
            'nodeType' => $node->getNodeType()->getName(),
            'name' => $node->getName(),
        ], $this->nodeDimensionInfo($node));
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

    /**
     * Available content dimensions: name, default value and the values (presets) with the
     * fallback chain each value resolves through.
     *
     * @return array<int, array<string, mixed>>
     */
    private function describeDimensions(): array
    {
        return $this->dimensionSelection()->describe();
    }

    /** @Flow\SkipCsrfProtection */
    public function listSitesAction(): void
    {
        $this->checkAuth();
        $sites = $this->siteService->listSites();
        $this->view->assign('value', ['count' => count($sites), 'sites' => $sites]);
    }

    /** @Flow\SkipCsrfProtection */
    public function getSiteContextAction(): void
    {
        $this->checkAuth();

        $siteNodePath = $this->getSiteNodePath();
        $site = $this->siteService->getCurrentSite();
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

        $sites = array_map(
            static fn (array $s): array => array_intersect_key($s, array_flip(['name', 'nodeName', 'online', 'default', 'primaryDomain'])),
            $this->siteService->listSites()
        );
        $multiSiteHint = count($sites) > 1
            ? sprintf(
                ' This Neos instance has %d sites; this context is for "%s". ' .
                'Pass "site" (a site node name from "sites") to work on another site.',
                count($sites),
                $site->getNodeName()
            )
            : '';

        $this->view->assign('value', [
            'dimensions' => $this->describeDimensions(),
            'siteName' => $site->getName(),
            'siteNodeName' => $site->getNodeName(),
            'siteNodePath' => $siteNodePath,
            'sites' => $sites,
            'mcpWorkspace' => $this->mcpWorkspaceName,
            'nodeTypes' => $nodeTypes,
            'pages' => $pages,
            'workflowInstructions' => sprintf(
                'Always write content changes to the "%s" workspace. ' .
                'Use neos_list_pending_changes to review staged changes. ' .
                'Use neos_get_preview_url to get a preview link without logging in to Neos. ' .
                'Only call neos_publish_changes after the user explicitly confirms they want to go live.',
                $this->mcpWorkspaceName
            ) . $multiSiteHint,
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
        // Accept a context path (/sites/x@mcp;language=nl) as well; the context comes from workspace and dimensions.
        $nodePath = preg_replace('/@.*$/', '', $nodePath);
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
        $nodePath = preg_replace('/@.*$/', '', $nodePath);
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
        $site = $this->siteService->hasSelectedSite() ? $this->siteService->getCurrentSite() : null;
        $unpublished = $this->unpublishedNodesOfSite($workspaceObject, $site);
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
                'site' => $this->siteService->siteNodeNameForPath($node->getPath()),
                'dimensions' => $this->nodeDimensionInfo($node)['dimensions'],
            ];
        }
        $this->view->assign('value', [
            'workspace' => $workspace,
            'site' => $site?->getNodeName(),
            'dimensions' => $this->requestedDimensions,
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
        $nodePath = preg_replace('/@.*$/', '', $nodePath);

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify('+24 hours');

        $payload = json_encode([
            'workspace' => $workspace,
            'nodePath' => $nodePath,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ]);
        $this->previewTokensCache->set($token, $payload);

        // Use the domain of the node's own site, not the host the bridge was called on.
        $site = $this->siteService->findSiteForPath($nodePath);
        $baseUrl = rtrim((string) $this->siteService->buildSiteBaseUri(
            $site?->getPrimaryDomain(),
            $this->request->getHttpRequest()->getUri()
        ), '/');

        // Build the frontend URL using uriPathSegment properties (not node names)
        $context = $this->createContext($workspace);
        $node = $context->getNode($nodePath);
        $siteNodePath = $site !== null ? '/sites/' . $site->getNodeName() : $this->getSiteNodePath();
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
        // With explicit dimensions the URL carries their segment (e.g. /uk/page); without, Neos resolves the default.
        $dimensionSegment = $this->requestedDimensionsUriSegment();
        if ($dimensionSegment !== '') {
            $frontendPath = '/' . $dimensionSegment . ($frontendPath === '/' ? '' : $frontendPath);
        }

        $previewUrl = $baseUrl . $frontendPath . '?_mcpPreview=' . $token;

        $this->view->assign('value', [
            'previewUrl' => $previewUrl,
            'site' => $site !== null ? (string)$site->getNodeName() : null,
            'token' => $token,
            'workspace' => $workspace,
            'nodePath' => $nodePath,
            'dimensions' => $this->requestedDimensions,
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
        $this->requireNodeInRequestedDimensions($parentNode, 'Parent node');

        $nodeTypeObject = $this->nodeTypeManager->getNodeType($nodeType);
        $this->requireNodeTypeAllowedIn($parentNode, $nodeTypeObject);
        $template = new NodeTemplate();
        $template->setNodeType($nodeTypeObject);

        $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
        $nodeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $template->setName($nodeName);

        foreach ($properties as $key => $value) {
            $propertyType = $nodeTypeObject->getPropertyType($key);
            try {
                $resolvedValue = $this->nodePropertyResolver->resolve($context, $propertyType, $value);
            } catch (NodePropertyResolutionException $e) {
                $this->throwStatus($e->getStatusCode(), null, json_encode(['error' => $e->getMessage()]));
            }
            $template->setProperty($key, $resolvedValue);
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
        $this->requireNodeInRequestedDimensions($parentNode, 'Parent node');

        // The sibling decides where the new node ends up, so it is resolved before anything is created
        $sibling = null;
        if ($insertBefore !== '' || $insertAfter !== '') {
            $siblingParameter = $insertBefore !== '' ? 'insertBefore' : 'insertAfter';
            $siblingContextPath = $insertBefore !== '' ? $insertBefore : $insertAfter;
            $sibling = $context->getNode(preg_replace('/@.*$/', '', $siblingContextPath));
            if ($sibling === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => $siblingParameter . ' node not found: ' . $siblingContextPath]));
            }
        }

        $nodeTypeObject = $this->nodeTypeManager->getNodeType($nodeType);
        $this->requireNodeTypeAllowedIn($parentNode, $nodeTypeObject);
        if ($sibling !== null) {
            $this->requireNodeTypeAllowedIn($sibling->getParent(), $nodeTypeObject);
        }
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
            $propertyType = $nodeTypeObject->getPropertyType($key);
            try {
                $resolvedValue = $this->nodePropertyResolver->resolve($context, $propertyType, $value);
            } catch (NodePropertyResolutionException $e) {
                $this->throwStatus($e->getStatusCode(), null, json_encode(['error' => $e->getMessage()]));
            }
            $template->setProperty($key, $resolvedValue);
        }

        $newNode = $parentNode->createNodeFromTemplate($template);

        if ($sibling !== null && $insertBefore !== '') {
            $newNode->moveBefore($sibling);
        } elseif ($sibling !== null) {
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
        $this->requireNodeInRequestedDimensions($node);

        if ($insertBefore !== '') {
            $refPath = preg_replace('/@.*$/', '', $insertBefore);
            $ref = $context->getNode($refPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertBefore node not found: ' . $insertBefore]));
            }
            $this->requireNodeInRequestedDimensions($ref, 'Reference node');
            $this->requireNodeTypeAllowedInNewParent($node, $ref->getParent());
            $node->moveBefore($ref);
        } elseif ($insertAfter !== '') {
            $refPath = preg_replace('/@.*$/', '', $insertAfter);
            $ref = $context->getNode($refPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'insertAfter node not found: ' . $insertAfter]));
            }
            $this->requireNodeInRequestedDimensions($ref, 'Reference node');
            $this->requireNodeTypeAllowedInNewParent($node, $ref->getParent());
            $node->moveAfter($ref);
        } else {
            $ref = $context->getNode($newParentPath);
            if ($ref === null) {
                $this->throwStatus(404, 'Not Found', json_encode(['error' => 'newParentPath node not found: ' . $newParentPath]));
            }
            $this->requireNodeInRequestedDimensions($ref, 'Target parent');
            $this->requireNodeTypeAllowedInNewParent($node, $ref);
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

        $this->requireNodeInRequestedDimensions($node);

        $nodeType = $node->getNodeType();
        $propertyType = $nodeType->getPropertyType($property);
        try {
            $resolvedValue = $this->nodePropertyResolver->resolve($context, $propertyType, $value);
        } catch (NodePropertyResolutionException $e) {
            $this->throwStatus($e->getStatusCode(), null, json_encode(['error' => $e->getMessage()]));
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

        $this->requireNodeInRequestedDimensions($node);

        $this->emitNodeMutated($node, 'Element removed');
        $node->remove();
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'removedContextPath' => $contextPath,
        ]);
    }

    /**
     * Creates a variant of a node in the dimensions given by the "dimensions" argument,
     * starting from the node as it exists in "sourceDimensions" (default: the default dimensions).
     * mode "copy" copies properties and content; mode "empty" creates the variant without
     * properties and content (auto-created child nodes are kept, empty).
     *
     * @Flow\SkipCsrfProtection
     */
    public function createNodeVariantAction(
        string $contextPath = '',
        string $sourceDimensions = '',
        string $mode = 'copy',
        string $workspace = 'mcp'
    ): void {
        $this->checkAuth();
        if ($contextPath === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'contextPath is required']));
        }
        if ($this->requestedDimensions === null) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'dimensions is required: the dimensions to create the variant in']));
        }
        if (!in_array($mode, ['copy', 'empty'], true)) {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'mode must be "copy" or "empty"']));
        }

        $source = $this->parseDimensionsArgument($sourceDimensions) ?? $this->defaultDimensions();
        $this->ensureMcpWorkspace();
        $nodePath = preg_replace('/@.*$/', '', $contextPath);

        $sourceNode = $this->createContextForDimensions($workspace, $source)->getNode($nodePath);
        if ($sourceNode === null) {
            $this->throwStatus(404, 'Not Found', json_encode(['error' => sprintf('Node %s not found in %s', $nodePath, json_encode($source))]));
        }

        $targetContext = $this->createContext($workspace);
        $existing = $targetContext->getNode($nodePath);
        if ($existing !== null && !$this->nodeDimensionInfo($existing)['fallback']) {
            $this->throwStatus(409, 'Conflict', json_encode(['error' => sprintf('Node %s already exists in %s', $nodePath, json_encode($this->requestedDimensions))]));
        }
        $parent = $sourceNode->getParent();
        if ($parent !== null && $targetContext->getNode($parent->getPath()) === null) {
            $this->throwStatus(409, 'Conflict', json_encode(['error' => sprintf(
                'Parent %s does not exist in %s. Create the variant of the parent first.',
                $parent->getPath(),
                json_encode($this->requestedDimensions)
            )]));
        }

        $newNode = $targetContext->adoptNode($sourceNode);
        if ($mode === 'copy') {
            foreach ($sourceNode->getChildNodes() as $child) {
                if (!$child->getNodeType()->isOfType('Neos.Neos:Document')) {
                    $targetContext->adoptNode($child, true);
                }
            }
        } else {
            foreach ($newNode->getPropertyNames() as $propertyName) {
                if ($propertyName !== 'uriPathSegment') {
                    $newNode->removeProperty($propertyName);
                }
            }
            $autoCreated = array_keys($sourceNode->getNodeType()->getAutoCreatedChildNodes());
            foreach ($sourceNode->getChildNodes() as $child) {
                if (in_array($child->getName(), $autoCreated, true)) {
                    $targetContext->adoptNode($child);
                }
            }
        }

        $this->emitNodeMutated($newNode, 'Variant created');
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'mode' => $mode,
            'sourceDimensions' => $source,
            'node' => $this->serializeNode($newNode),
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

        // With a selected site only that site's changes are published; the changes
        // of other sites stay in the workspace.
        $site = $this->siteService->hasSelectedSite() ? $this->siteService->getCurrentSite() : null;
        $unpublished = $this->unpublishedNodesOfSite($workspaceObject, $site);
        $count = count($unpublished);

        if ($count > 0) {
            $this->publishingService->publishNodes($unpublished);
            $this->persistenceManager->persistAll();
        }

        $this->view->assign('value', [
            'success' => true,
            'publishedNodes' => $count,
            'workspace' => $workspace,
            'site' => $site?->getNodeName(),
            'dimensions' => $this->requestedDimensions,
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

        if ($tag !== '') {
            // Filter in the query (not afterwards), so total, limit and offset describe the filtered set.
            $tagObject = $this->tagRepository->findOneByLabel($tag);
            if ($tagObject === null) {
                $this->view->assign('value', [
                    'assets' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'mediaTypeFilter' => $mediaType,
                    'tagFilter' => $tag,
                ]);
                return;
            }
            $constraints[] = $query->contains('tags', $tagObject);
        }

        if (!empty($constraints)) {
            $query->matching($query->logicalAnd($constraints));
        }

        // Count before limit/offset are applied: count() honours them, which would cap total at the page size.
        $total = $query->count();

        $query->setOrderings(['lastModified' => \Neos\Flow\Persistence\QueryInterface::ORDER_DESCENDING]);
        $query->setLimit($limit);
        $query->setOffset($offset);

        $assets = $query->execute();

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

    /**
     * Upload a file (base64 encoded) into the Neos Media Manager.
     *
     * If an asset with identical file content already exists, that asset is returned
     * instead of creating a duplicate (unless allowDuplicate is set).
     *
     * @param array $tags Tag labels; missing tags are created
     * @param array $assetCollections Asset collection titles; missing collections are created
     * @Flow\SkipCsrfProtection
     */
    public function uploadAssetAction(
        string $filename = '',
        string $content = '',
        string $title = '',
        string $caption = '',
        string $copyrightNotice = '',
        array $tags = [],
        array $assetCollections = [],
        bool $allowDuplicate = false
    ): void {
        $this->checkAuth();
        if ($filename === '' || $content === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'filename and content (base64) are required']));
        }

        $filename = basename(str_replace('\\', '/', $filename));
        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            $this->throwStatus(400, 'Bad Request', json_encode(['error' => 'content is not valid base64']));
        }

        try {
            if (!$allowDuplicate) {
                $existing = $this->assetRepository->findOneByResourceSha1(sha1($binary));
                if ($existing !== null) {
                    $this->view->assign('value', [
                        'success' => true,
                        'duplicate' => true,
                        'asset' => $this->serializeAsset($existing),
                    ]);
                    return;
                }
            }

            $resource = $this->resourceManager->importResourceFromContent($binary, $filename);
            $assetClassName = $this->assetModelMappingStrategy->map($resource);
            /** @var Asset $asset */
            $asset = new $assetClassName($resource);

            if ($title !== '') {
                $asset->setTitle($title);
            }
            if ($caption !== '') {
                $asset->setCaption($caption);
            }
            if ($copyrightNotice !== '') {
                $asset->setCopyrightNotice($copyrightNotice);
            }

            foreach (array_unique(array_filter(array_map('trim', $tags))) as $label) {
                $tag = $this->tagRepository->findOneByLabel($label);
                if ($tag === null) {
                    $tag = new Tag($label);
                    $this->tagRepository->add($tag);
                }
                $asset->addTag($tag);
            }

            $this->assetRepository->add($asset);

            foreach (array_unique(array_filter(array_map('trim', $assetCollections))) as $collectionTitle) {
                $collection = $this->assetCollectionRepository->findOneByTitle($collectionTitle);
                if ($collection === null) {
                    $collection = new AssetCollection($collectionTitle);
                    $this->assetCollectionRepository->add($collection);
                }
                if ($collection->addAsset($asset)) {
                    $asset->getAssetCollections()->add($collection);
                }
                $this->assetCollectionRepository->update($collection);
            }

            $this->persistenceManager->persistAll();

            $this->view->assign('value', [
                'success' => true,
                'duplicate' => false,
                'asset' => $this->serializeAsset($asset),
            ]);
        } catch (\Neos\Flow\Mvc\Exception\StopActionException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->throwStatus(500, 'Internal Server Error', json_encode(['error' => $e->getMessage()]));
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
    protected function emitNodeMutated(NodeInterface $node, string $changeDescription): void
    {
    }
}
