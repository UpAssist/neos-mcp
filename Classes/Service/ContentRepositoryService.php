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
use Neos\ContentRepository\Core\NodeType\NodeType;
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

    /**
     * Memoized default site, since it's immutable for the duration of a
     * request and several methods on this singleton resolve it independently.
     */
    private ?Site $defaultSite = null;

    // -------------------------------------------------------------------------
    // Core accessors
    // -------------------------------------------------------------------------

    public function getDefaultSite(): Site
    {
        if ($this->defaultSite === null) {
            $site = $this->siteRepository->findDefault();
            if ($site === null) {
                throw new \RuntimeException('No default site found', 1712000001);
            }
            $this->defaultSite = $site;
        }
        return $this->defaultSite;
    }

    public function getContentRepositoryId(): ContentRepositoryId
    {
        return $this->getDefaultSite()->getConfiguration()->contentRepositoryId;
    }

    public function getContentRepository(): ContentRepository
    {
        return $this->contentRepositoryRegistry->get($this->getContentRepositoryId());
    }

    /**
     * The site's configured default dimension space point (e.g. {language: de}).
     * Sites without content dimensions resolve this to the dimensionless point,
     * so this is safe to use in place of DimensionSpacePoint::createWithoutDimensions()
     * everywhere a node's actual dimension variant is needed.
     */
    public function getDefaultDimensionSpacePoint(): DimensionSpacePoint
    {
        return $this->getDefaultSite()->getConfiguration()->defaultDimensionSpacePoint;
    }

    public function getSubgraph(string $workspace = 'live'): ContentSubgraphInterface
    {
        return $this->getContentRepository()->getContentSubgraph(
            WorkspaceName::fromString($workspace),
            $this->getDefaultDimensionSpacePoint()
        );
    }

    public function getSiteNode(string $workspace = 'live'): Node
    {
        return $this->siteNodeUtility->findSiteNodeBySite(
            $this->getDefaultSite(),
            WorkspaceName::fromString($workspace),
            $this->getDefaultDimensionSpacePoint()
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
        $nodeTypeName = NodeTypeName::fromString($nodeType);

        [$regularProperties, $referenceProperties] = $this->resolveCreateProperties($nodeTypeName, $properties, $workspace);

        $command = CreateNodeAggregateWithNode::create(
            WorkspaceName::fromString($workspace),
            $newNodeId,
            $nodeTypeName,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->getDefaultDimensionSpacePoint()),
            NodeAggregateId::fromString($parentNodeAggregateId),
            $succeedingSiblingId !== null ? NodeAggregateId::fromString($succeedingSiblingId) : null,
            !empty($regularProperties) ? PropertyValuesToWrite::fromArray($regularProperties) : null,
        );

        if ($nodeName !== null) {
            $command = $command->withNodeName(NodeName::fromString($nodeName));
        }

        $this->getContentRepository()->handle($command);

        foreach ($referenceProperties as $referenceName => $targetIds) {
            $this->setNodeReferences($workspace, $newNodeId->value, $referenceName, $targetIds);
        }

        return $newNodeId;
    }

    /**
     * Resolve raw create-time properties against the target NodeType, and split
     * off reference/references properties — those aren't part of
     * PropertyValuesToWrite and must be set via a separate SetNodeReferences
     * command once the node exists.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string[]>}
     */
    private function resolveCreateProperties(NodeTypeName $nodeTypeName, array $properties, string $workspace): array
    {
        if (empty($properties)) {
            return [[], []];
        }

        $nodeType = $this->getContentRepository()->getNodeTypeManager()->getNodeType($nodeTypeName);
        if ($nodeType === null) {
            return [$properties, []];
        }

        $regularProperties = [];
        $referenceProperties = [];

        foreach ($properties as $propertyName => $rawValue) {
            $propertyType = $nodeType->getPropertyType($propertyName);

            if ($propertyType === 'reference') {
                $referenceProperties[$propertyName] = $rawValue !== '' && $rawValue !== null ? [$rawValue] : [];
                continue;
            }

            if ($propertyType === 'references') {
                $referenceProperties[$propertyName] = $this->resolveReferenceIdentifiers($rawValue, $workspace);
                continue;
            }

            $regularProperties[$propertyName] = $this->resolvePropertyValueForNodeType($nodeType, $propertyName, $rawValue);
        }

        return [$regularProperties, $referenceProperties];
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
        $node = $this->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            throw new \RuntimeException('Node not found: ' . $nodeAggregateId, 1712000007);
        }

        $command = MoveNodeAggregate::create(
            WorkspaceName::fromString($workspace),
            $node->originDimensionSpacePoint->toDimensionSpacePoint(),
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
        $node = $this->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            throw new \RuntimeException('Node not found: ' . $nodeAggregateId, 1712000008);
        }

        $command = RemoveNodeAggregate::create(
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            $node->originDimensionSpacePoint->toDimensionSpacePoint(),
            NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
        );

        $this->getContentRepository()->handle($command);
    }

    public function setNodeHidden(string $workspace, string $nodeAggregateId, bool $hidden): void
    {
        $node = $this->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            throw new \RuntimeException('Node not found: ' . $nodeAggregateId, 1712000009);
        }

        $wsName = WorkspaceName::fromString($workspace);
        $nodeId = NodeAggregateId::fromString($nodeAggregateId);
        $dsp = $node->originDimensionSpacePoint->toDimensionSpacePoint();
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
                $result[$propertyName] = is_object($value)
                    ? ['__type' => 'asset', 'identifier' => $this->persistenceManager->getIdentifierByObject($value)]
                    : null;
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

        return $this->resolvePropertyValueForNodeType($nodeType, $propertyName, $rawValue);
    }

    /**
     * Same resolution as resolvePropertyValue(), but keyed off a NodeType directly
     * instead of an existing Node — used on the create path, where the node doesn't
     * exist yet when properties need to be resolved.
     */
    public function resolvePropertyValueForNodeType(NodeType $nodeType, string $propertyName, mixed $rawValue): mixed
    {
        $propertyType = $nodeType->getPropertyType($propertyName);

        // Asset resolution
        if ($propertyType !== null && (str_contains($propertyType, 'Image') || str_contains($propertyType, 'Asset'))) {
            $assetIdentifier = $rawValue;
            if (is_string($rawValue) && str_starts_with(trim($rawValue), '{')) {
                $decoded = json_decode($rawValue, true);
                if (is_array($decoded) && isset($decoded['identifier'])) {
                    $assetIdentifier = $decoded['identifier'];
                }
            } elseif (is_array($rawValue) && isset($rawValue['identifier'])) {
                // Create-path properties come from a decoded JSON body, so the
                // {"__type":"asset","identifier":"..."} object arrives as a PHP
                // array already, not a JSON string.
                $assetIdentifier = $rawValue['identifier'];
            }
            $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
            if ($asset === null) {
                throw new \RuntimeException('Asset not found: ' . $assetIdentifier, 1712000004);
            }
            return $asset;
        }

        // Boolean handling — coerce by property type so JSON true/false/0/1/""
        // all end up as a real PHP bool on boolean-typed properties.
        if ($propertyType === 'boolean' || $propertyType === 'bool') {
            if (is_bool($rawValue)) {
                return $rawValue;
            }
            if (is_string($rawValue)) {
                $normalized = strtolower(trim($rawValue));
                return in_array($normalized, ['true', '1', 'yes', 'on'], true);
            }
            return (bool) $rawValue;
        }

        // Legacy string coercion for callers that didn't declare a boolean schema
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
     * Accepts a JSON array string, a comma-separated string, or an already-decoded
     * PHP array (create-path properties come from a decoded JSON body). Returns an
     * array of validated node aggregate IDs.
     */
    public function resolveReferenceIdentifiers(mixed $rawValue, string $workspace): array
    {
        if (is_string($rawValue)) {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded)) {
                $identifiers = $decoded;
            } else {
                $identifiers = array_filter(array_map('trim', explode(',', $rawValue)));
            }
        } elseif (is_array($rawValue)) {
            $identifiers = $rawValue;
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
