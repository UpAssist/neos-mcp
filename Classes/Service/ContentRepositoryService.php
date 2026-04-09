<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferenceToWrite;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteNodeUtility;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * Shared service encapsulating all Neos 9 ContentRepository interactions.
 * Used by both McpBridgeController (HTTP) and McpCommandController (CLI).
 *
 * @Flow\Scope("singleton")
 */
class ContentRepositoryService
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var SiteNodeUtility
     */
    protected $siteNodeUtility;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceService
     */
    protected $workspaceService;

    /**
     * @Flow\Inject
     * @var WorkspacePublishingService
     */
    protected $workspacePublishingService;

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

    // -------------------------------------------------------------------------
    // Core accessors
    // -------------------------------------------------------------------------

    public function getDefaultSite(): Site
    {
        $site = $this->siteRepository->findDefault();
        if ($site === null) {
            throw new \RuntimeException('No default site found', 1712000001);
        }
        return $site;
    }

    public function getContentRepositoryId(): ContentRepositoryId
    {
        return $this->getDefaultSite()->getConfiguration()->contentRepositoryId;
    }

    public function getContentRepository(): ContentRepository
    {
        return $this->contentRepositoryRegistry->get($this->getContentRepositoryId());
    }

    public function getSubgraph(string $workspace = 'live'): ContentSubgraphInterface
    {
        return $this->getContentRepository()->getContentSubgraph(
            WorkspaceName::fromString($workspace),
            DimensionSpacePoint::createWithoutDimensions()
        );
    }

    public function getSiteNode(string $workspace = 'live'): Node
    {
        return $this->siteNodeUtility->findSiteNodeBySite(
            $this->getDefaultSite(),
            WorkspaceName::fromString($workspace),
            DimensionSpacePoint::createWithoutDimensions()
        );
    }

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    public function findNodeById(string $nodeAggregateId, string $workspace = 'live'): ?Node
    {
        return $this->getSubgraph($workspace)->findNodeById(
            NodeAggregateId::fromString($nodeAggregateId)
        );
    }

    public function findChildNodes(NodeAggregateId $parentId, string $workspace = 'live', ?string $nodeTypeFilter = null): Nodes
    {
        $filter = $nodeTypeFilter !== null
            ? FindChildNodesFilter::create(nodeTypes: $nodeTypeFilter)
            : FindChildNodesFilter::create();

        return $this->getSubgraph($workspace)->findChildNodes($parentId, $filter);
    }

    public function findParentNode(NodeAggregateId $childId, string $workspace = 'live'): ?Node
    {
        return $this->getSubgraph($workspace)->findParentNode($childId);
    }

    public function findClosestDocument(NodeAggregateId $nodeId, string $workspace = 'live'): ?Node
    {
        return $this->getSubgraph($workspace)->findClosestNode(
            $nodeId,
            FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document')
        );
    }

    public function findAncestorNodes(NodeAggregateId $nodeId, string $workspace = 'live', ?string $nodeTypeFilter = null): Nodes
    {
        $filter = $nodeTypeFilter !== null
            ? FindAncestorNodesFilter::create(nodeTypes: $nodeTypeFilter)
            : FindAncestorNodesFilter::create();

        return $this->getSubgraph($workspace)->findAncestorNodes($nodeId, $filter);
    }

    // -------------------------------------------------------------------------
    // Workspace operations
    // -------------------------------------------------------------------------

    public function workspaceExists(string $workspaceName): bool
    {
        return $this->getContentRepository()->findWorkspaceByName(
            WorkspaceName::fromString($workspaceName)
        ) !== null;
    }

    public function ensureWorkspace(string $workspaceName, string $title, string $description): void
    {
        $wsName = WorkspaceName::fromString($workspaceName);
        $crId = $this->getContentRepositoryId();

        if ($this->getContentRepository()->findWorkspaceByName($wsName) !== null) {
            // Workspace exists — ensure role assignments are in place
            try {
                $this->workspaceService->assignWorkspaceRole(
                    $crId, $wsName,
                    WorkspaceRoleAssignment::createForGroup('Neos.Neos:Administrator', WorkspaceRole::MANAGER)
                );
            } catch (\Exception $e) {
                // Role may already exist
            }
            try {
                $this->workspaceService->assignWorkspaceRole(
                    $crId, $wsName,
                    WorkspaceRoleAssignment::createForGroup('Neos.Neos:Editor', WorkspaceRole::COLLABORATOR)
                );
            } catch (\Exception $e) {
                // Role may already exist
            }
            return;
        }

        $this->workspaceService->createSharedWorkspace(
            $crId,
            $wsName,
            new WorkspaceTitle($title),
            new WorkspaceDescription($description),
            WorkspaceName::forLive(),
            WorkspaceRoleAssignments::create(
                WorkspaceRoleAssignment::createForGroup('Neos.Neos:Administrator', WorkspaceRole::MANAGER),
                WorkspaceRoleAssignment::createForGroup('Neos.Neos:Editor', WorkspaceRole::COLLABORATOR),
            )
        );
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    public function createNode(
        string $workspace,
        string $parentNodeAggregateId,
        string $nodeType,
        array $properties = [],
        ?string $nodeName = null,
        ?string $succeedingSiblingId = null,
    ): NodeAggregateId {
        $newNodeId = NodeAggregateId::create();

        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::fromString($workspace),
            $newNodeId,
            NodeTypeName::fromString($nodeType),
            OriginDimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateId::fromString($parentNodeAggregateId),
            $succeedingSiblingId !== null ? NodeAggregateId::fromString($succeedingSiblingId) : null,
            !empty($properties) ? PropertyValuesToWrite::fromArray($properties) : null,
        );

        if ($nodeName !== null) {
            $command = $command->withNodeName(NodeName::fromString($nodeName));
        }

        $this->getContentRepository()->handle($command);

        return $newNodeId;
    }

    public function setNodeProperties(string $workspace, string $nodeAggregateId, array $properties): void
    {
        $node = $this->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            throw new \RuntimeException('Node not found: ' . $nodeAggregateId, 1712000002);
        }

        $command = SetNodeProperties::create(
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            $node->originDimensionSpacePoint,
            PropertyValuesToWrite::fromArray($properties),
        );

        $this->getContentRepository()->handle($command);
    }

    public function setNodeReferences(string $workspace, string $nodeAggregateId, string $referenceName, array $targetIds): void
    {
        $node = $this->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            throw new \RuntimeException('Node not found: ' . $nodeAggregateId, 1712000003);
        }

        $targetNodeAggregateIds = NodeAggregateIds::fromArray(
            array_map(fn(string $id) => NodeAggregateId::fromString($id), $targetIds)
        );

        $referencesForName = NodeReferencesForName::fromTargets(
            ReferenceName::fromString($referenceName),
            $targetNodeAggregateIds
        );

        $command = SetNodeReferences::create(
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            $node->originDimensionSpacePoint,
            NodeReferencesToWrite::create($referencesForName),
        );

        $this->getContentRepository()->handle($command);
    }

    public function moveNode(
        string $workspace,
        string $nodeAggregateId,
        ?string $newParentId = null,
        ?string $newPrecedingSiblingId = null,
        ?string $newSucceedingSiblingId = null,
    ): void {
        $command = MoveNodeAggregate::create(
            WorkspaceName::fromString($workspace),
            DimensionSpacePoint::createWithoutDimensions(),
            NodeAggregateId::fromString($nodeAggregateId),
            RelationDistributionStrategy::STRATEGY_GATHER_ALL,
            $newParentId !== null ? NodeAggregateId::fromString($newParentId) : null,
            $newPrecedingSiblingId !== null ? NodeAggregateId::fromString($newPrecedingSiblingId) : null,
            $newSucceedingSiblingId !== null ? NodeAggregateId::fromString($newSucceedingSiblingId) : null,
        );

        $this->getContentRepository()->handle($command);
    }

    public function removeNode(string $workspace, string $nodeAggregateId): void
    {
        $command = RemoveNodeAggregate::create(
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            DimensionSpacePoint::createWithoutDimensions(),
            NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
        );

        $this->getContentRepository()->handle($command);
    }

    public function setNodeHidden(string $workspace, string $nodeAggregateId, bool $hidden): void
    {
        $wsName = WorkspaceName::fromString($workspace);
        $nodeId = NodeAggregateId::fromString($nodeAggregateId);
        $dsp = DimensionSpacePoint::createWithoutDimensions();
        $strategy = NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS;

        if ($hidden) {
            $command = TagSubtree::create($wsName, $nodeId, $dsp, $strategy, NeosSubtreeTag::disabled());
        } else {
            $command = UntagSubtree::create($wsName, $nodeId, $dsp, $strategy, NeosSubtreeTag::disabled());
        }

        $this->getContentRepository()->handle($command);
    }

    // -------------------------------------------------------------------------
    // Publishing
    // -------------------------------------------------------------------------

    public function getPendingChanges(string $workspace): array
    {
        $changes = $this->workspacePublishingService->pendingWorkspaceChanges(
            $this->getContentRepositoryId(),
            WorkspaceName::fromString($workspace)
        );

        $subgraph = $this->getSubgraph($workspace);
        $liveSubgraph = $this->getSubgraph('live');
        $result = [];

        foreach ($changes as $change) {
            $node = $subgraph->findNodeById($change->nodeAggregateId);
            $liveNode = $liveSubgraph->findNodeById($change->nodeAggregateId);

            $changeType = 'modified';
            if ($change->created) {
                $changeType = 'added';
            } elseif ($change->deleted) {
                $changeType = 'removed';
            } elseif ($change->moved) {
                $changeType = 'moved';
            }

            $result[] = [
                'nodeAggregateId' => $change->nodeAggregateId->value,
                'nodeType' => $node?->nodeTypeName->value ?? 'unknown',
                'changeType' => $changeType,
            ];
        }

        return $result;
    }

    public function publishWorkspace(string $workspace): int
    {
        $changes = $this->workspacePublishingService->pendingWorkspaceChanges(
            $this->getContentRepositoryId(),
            WorkspaceName::fromString($workspace)
        );
        $count = count($changes);

        $this->workspacePublishingService->publishWorkspace(
            $this->getContentRepositoryId(),
            WorkspaceName::fromString($workspace)
        );

        return $count;
    }

    // -------------------------------------------------------------------------
    // Serialization helpers
    // -------------------------------------------------------------------------

    public function serializeNode(Node $node, ?ContentSubgraphInterface $subgraph = null): array
    {
        $path = null;
        if ($subgraph !== null) {
            try {
                $path = $this->computeNodePath($node, $subgraph);
            } catch (\Exception $e) {
                $path = null;
            }
        }

        return [
            'nodeAggregateId' => $node->aggregateId->value,
            'nodeType' => $node->nodeTypeName->value,
            'name' => $node->name?->value,
            'path' => $path,
        ];
    }

    public function serializeNodeProperties(Node $node): array
    {
        $cr = $this->getContentRepository();
        $nodeType = $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return [];
        }

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
            if (str_contains($type, 'Image') || str_contains($type, 'Asset') || str_contains($type, 'Media')) {
                $result[$propertyName] = [
                    '__type' => 'asset',
                    'identifier' => $this->persistenceManager->getIdentifierByObject($value),
                ];
            } elseif ($value instanceof \DateTimeInterface) {
                $result[$propertyName] = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_object($value)) {
                try {
                    $result[$propertyName] = [
                        '__type' => get_class($value),
                        'identifier' => $this->persistenceManager->getIdentifierByObject($value),
                    ];
                } catch (\Exception $e) {
                    $result[$propertyName] = null;
                }
            } else {
                $result[$propertyName] = $value;
            }
        }

        return $result;
    }

    /**
     * Serialize node references for a given node.
     * In Neos 9, references are read via the subgraph, not from node properties.
     */
    public function serializeNodeReferences(Node $node, string $workspace = 'live'): array
    {
        $subgraph = $this->getSubgraph($workspace);
        $references = $subgraph->findReferences($node->aggregateId, FindReferencesFilter::create());

        $result = [];
        foreach ($references as $reference) {
            $refName = $reference->name->value;
            if (!isset($result[$refName])) {
                $result[$refName] = [];
            }
            $result[$refName][] = $reference->node->aggregateId->value;
        }

        return $result;
    }

    public function isNodeHidden(Node $node): bool
    {
        return $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled());
    }

    /**
     * Compute a human-readable path from the site root to the given node.
     */
    private function computeNodePath(Node $node, ContentSubgraphInterface $subgraph): string
    {
        $segments = [];
        $current = $node;

        while ($current !== null && $current->name !== null) {
            $segments[] = $current->name->value;
            $current = $subgraph->findParentNode($current->aggregateId);
        }

        return '/' . implode('/', array_reverse($segments));
    }

    // -------------------------------------------------------------------------
    // Property value resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a raw property value to the correct PHP type based on the node type schema.
     * Handles assets, references, dates, booleans, arrays.
     */
    public function resolvePropertyValue(Node $node, string $propertyName, mixed $rawValue): mixed
    {
        $cr = $this->getContentRepository();
        $nodeType = $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return $rawValue;
        }

        $propertyType = $nodeType->getPropertyType($propertyName);

        // Asset resolution
        if ($propertyType !== null && (str_contains($propertyType, 'Image') || str_contains($propertyType, 'Asset'))) {
            $assetIdentifier = $rawValue;
            if (is_string($rawValue) && str_starts_with(trim($rawValue), '{')) {
                $decoded = json_decode($rawValue, true);
                if (is_array($decoded) && isset($decoded['identifier'])) {
                    $assetIdentifier = $decoded['identifier'];
                }
            }
            $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
            if ($asset === null) {
                throw new \RuntimeException('Asset not found: ' . $assetIdentifier, 1712000004);
            }
            return $asset;
        }

        // Boolean handling
        if (is_string($rawValue) && in_array(strtolower($rawValue), ['true', 'false'], true)) {
            return strtolower($rawValue) === 'true';
        }

        // DateTime handling
        if ($propertyType === 'DateTime' && is_string($rawValue) && $rawValue !== '') {
            try {
                return new \DateTime($rawValue);
            } catch (\Exception $e) {
                throw new \RuntimeException('Invalid date format: ' . $rawValue, 1712000005);
            }
        }

        // Array handling (JSON string → PHP array)
        if ($propertyType === 'array' && is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $rawValue;
    }

    /**
     * Resolve reference identifiers for 'reference' and 'references' property types.
     * Returns an array of validated node aggregate IDs.
     */
    public function resolveReferenceIdentifiers(string $rawValue, string $workspace): array
    {
        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded)) {
                $identifiers = $decoded;
            } else {
                $identifiers = array_filter(array_map('trim', explode(',', $rawValue)));
            }
        } else {
            $identifiers = [];
        }

        $subgraph = $this->getSubgraph($workspace);
        $validated = [];
        foreach ($identifiers as $identifier) {
            $refNode = $subgraph->findNodeById(NodeAggregateId::fromString($identifier));
            if ($refNode !== null) {
                $validated[] = $identifier;
            }
        }

        return $validated;
    }

    // -------------------------------------------------------------------------
    // Node type introspection
    // -------------------------------------------------------------------------

    public function getNodeTypes(string $filter = 'content'): array
    {
        $cr = $this->getContentRepository();
        $nodeTypeManager = $cr->getNodeTypeManager();
        $nodeTypes = [];

        foreach ($nodeTypeManager->getNodeTypes(false) as $nodeType) {
            if ($filter === 'content' && !$nodeType->isOfType('Neos.Neos:Content')) {
                continue;
            }
            if ($filter === 'document' && !$nodeType->isOfType('Neos.Neos:Document')) {
                continue;
            }
            if ($filter === 'all' && !$nodeType->isOfType('Neos.Neos:Content') && !$nodeType->isOfType('Neos.Neos:Document')) {
                continue;
            }

            $properties = [];
            foreach ($nodeType->getProperties() as $name => $config) {
                if (str_starts_with($name, '_')) {
                    continue;
                }
                $properties[$name] = [
                    'type' => $nodeType->getPropertyType($name),
                    'label' => $config['ui']['label'] ?? $name,
                ];
            }

            $nodeTypes[] = [
                'name' => $nodeType->name->value,
                'isContent' => $nodeType->isOfType('Neos.Neos:Content'),
                'isDocument' => $nodeType->isOfType('Neos.Neos:Document'),
                'properties' => $properties,
            ];
        }

        return $nodeTypes;
    }

    // -------------------------------------------------------------------------
    // Document tree traversal
    // -------------------------------------------------------------------------

    public function collectDocumentNodes(Node $node, string $workspace = 'live', int $depth = 0, bool $includeProperties = true): array
    {
        $subgraph = $this->getSubgraph($workspace);
        $pages = [];

        $entry = array_merge($this->serializeNode($node, $subgraph), [
            'title' => $node->getProperty('title') ?? $node->name?->value ?? '',
            'hidden' => $this->isNodeHidden($node),
            'depth' => $depth,
        ]);

        if ($includeProperties) {
            $entry['properties'] = $this->serializeNodeProperties($node);
        }

        $pages[] = $entry;

        $children = $subgraph->findChildNodes(
            $node->aggregateId,
            FindChildNodesFilter::create(nodeTypes: 'Neos.Neos:Document')
        );

        foreach ($children as $child) {
            foreach ($this->collectDocumentNodes($child, $workspace, $depth + 1, $includeProperties) as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * Recursively collect all ContentCollection nodes beneath a given node,
     * including those nested inside content nodes (e.g. Column nodes inside a Columns wrapper).
     */
    public function collectContentCollections(Node $node, string $workspace = 'live'): array
    {
        $subgraph = $this->getSubgraph($workspace);
        $cr = $this->getContentRepository();
        $collections = [];

        $children = $subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create());
        foreach ($children as $child) {
            $childNodeType = $cr->getNodeTypeManager()->getNodeType($child->nodeTypeName);
            if ($childNodeType === null) {
                continue;
            }
            if ($childNodeType->isOfType('Neos.Neos:ContentCollection')) {
                $collections[] = $this->serializeNode($child, $subgraph);
                // Recurse to find nested collections inside this collection
                foreach ($this->collectContentCollections($child, $workspace) as $nested) {
                    $collections[] = $nested;
                }
            } elseif (!$childNodeType->isOfType('Neos.Neos:Document')) {
                // Recurse into non-document content nodes (e.g. Columns wrapper contains Column collections)
                foreach ($this->collectContentCollections($child, $workspace) as $nested) {
                    $collections[] = $nested;
                }
            }
        }

        return $collections;
    }

    public function collectContentNodes(Node $node, string $workspace = 'live'): array
    {
        $subgraph = $this->getSubgraph($workspace);
        $nodes = [];

        $children = $subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create());

        foreach ($children as $child) {
            $cr = $this->getContentRepository();
            $childNodeType = $cr->getNodeTypeManager()->getNodeType($child->nodeTypeName);
            if ($childNodeType !== null && $childNodeType->isOfType('Neos.Neos:Document')) {
                continue;
            }

            $nodes[] = array_merge($this->serializeNode($child, $subgraph), [
                'properties' => $this->serializeNodeProperties($child),
            ]);

            foreach ($this->collectContentNodes($child, $workspace) as $nested) {
                $nodes[] = $nested;
            }
        }

        return $nodes;
    }

    // -------------------------------------------------------------------------
    // Preview URL building
    // -------------------------------------------------------------------------

    /**
     * Build the frontend URI path for a document node by traversing ancestors and reading uriPathSegment.
     */
    public function buildFrontendPath(Node $node, string $workspace): string
    {
        $subgraph = $this->getSubgraph($workspace);
        $siteNode = $this->getSiteNode($workspace);
        $segments = [];
        $current = $node;

        while ($current !== null && $current->aggregateId->value !== $siteNode->aggregateId->value) {
            $segment = $current->getProperty('uriPathSegment');
            if ($segment !== null && $segment !== '') {
                array_unshift($segments, $segment);
            }
            $current = $subgraph->findParentNode($current->aggregateId);
        }

        return '/' . implode('/', $segments);
    }
}
