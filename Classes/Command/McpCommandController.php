<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

/**
 * CLI commands for MCP content operations.
 * All commands output JSON for machine consumption (MCP/AI tools).
 */
class McpCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryService
     */
    protected $crService;

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

    /**
     * Show site context: site info, node types, and page tree
     */
    public function siteContextCommand(): void
    {
        $site = $this->crService->getDefaultSite();
        $siteNode = $this->crService->getSiteNode('live');
        $nodeTypes = $this->crService->getNodeTypes('all');
        $pages = $this->crService->collectDocumentNodes($siteNode, 'live');

        $this->outputJson([
            'apiVersion' => 2,
            'siteName' => $site->getName(),
            'siteNodeName' => $site->getNodeName(),
            'siteNodeAggregateId' => $siteNode->aggregateId->value,
            'mcpWorkspace' => $this->mcpWorkspaceName,
            'nodeTypes' => $nodeTypes,
            'pages' => $pages,
        ]);
    }

    /**
     * List all document pages
     */
    public function listPagesCommand(string $workspace = 'mcp'): void
    {
        $siteNode = $this->crService->getSiteNode($workspace);
        $pages = $this->crService->collectDocumentNodes($siteNode, $workspace);
        $this->outputJson(['pages' => $pages]);
    }

    /**
     * Get content nodes for a specific page
     */
    public function getPageContentCommand(string $nodeAggregateId, string $workspace = 'mcp'): void
    {
        $pageNode = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($pageNode === null) {
            $this->outputJson(['error' => 'Node not found: ' . $nodeAggregateId]);
            $this->quit(1);
            return;
        }

        $subgraph = $this->crService->getSubgraph($workspace);
        $cr = $this->crService->getContentRepository();

        $contentNodes = [];
        $children = $this->crService->findChildNodes($pageNode->aggregateId, $workspace);
        foreach ($children as $child) {
            $childNodeType = $cr->getNodeTypeManager()->getNodeType($child->nodeTypeName);
            if ($childNodeType !== null && $childNodeType->isOfType('Neos.Neos:ContentCollection')) {
                foreach ($this->crService->collectContentNodes($child, $workspace) as $node) {
                    $contentNodes[] = $node;
                }
            }
        }

        $this->outputJson([
            'page' => array_merge($this->crService->serializeNode($pageNode, $subgraph), [
                'title' => $pageNode->getProperty('title') ?? $pageNode->name?->value ?? '',
                'properties' => $this->crService->serializeNodeProperties($pageNode),
            ]),
            'contentNodes' => $contentNodes,
        ]);
    }

    /**
     * Create a new content node
     */
    public function createContentNodeCommand(string $parentId, string $nodeType, string $properties = '{}', string $workspace = 'mcp'): void
    {
        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

        $props = json_decode($properties, true) ?? [];
        $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
        $nodeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $newNodeId = $this->crService->createNode($workspace, $parentId, $nodeType, $props, $nodeName);
        $newNode = $this->crService->findNodeById($newNodeId->value, $workspace);
        $subgraph = $this->crService->getSubgraph($workspace);

        $this->outputJson([
            'success' => true,
            'node' => $newNode !== null ? $this->crService->serializeNode($newNode, $subgraph) : ['nodeAggregateId' => $newNodeId->value],
        ]);
    }

    /**
     * Create a new document node
     */
    public function createDocumentNodeCommand(string $parentId, string $nodeType, string $nodeName = '', string $properties = '{}', string $workspace = 'mcp'): void
    {
        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

        $props = json_decode($properties, true) ?? [];

        if ($nodeName !== '') {
            $safeName = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $nodeName));
        } else {
            $safeName = strtolower(preg_replace('/[^a-z0-9]/i', '-', $nodeType));
            $safeName = $safeName . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $newNodeId = $this->crService->createNode($workspace, $parentId, $nodeType, $props, $safeName);
        $newNode = $this->crService->findNodeById($newNodeId->value, $workspace);
        $subgraph = $this->crService->getSubgraph($workspace);

        $this->outputJson([
            'success' => true,
            'node' => $newNode !== null ? $this->crService->serializeNode($newNode, $subgraph) : ['nodeAggregateId' => $newNodeId->value],
        ]);
    }

    /**
     * Update a single property on a node
     */
    public function updateNodePropertyCommand(string $nodeAggregateId, string $property, string $value, string $workspace = 'mcp'): void
    {
        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

        $node = $this->crService->findNodeById($nodeAggregateId, $workspace);
        if ($node === null) {
            $this->outputJson(['error' => 'Node not found: ' . $nodeAggregateId]);
            $this->quit(1);
            return;
        }

        if ($property === '_hidden') {
            $hidden = strtolower($value) === 'true' || $value === '1';
            $this->crService->setNodeHidden($workspace, $nodeAggregateId, $hidden);
        } else {
            $resolvedValue = $this->crService->resolvePropertyValue($node, $property, $value);
            $this->crService->setNodeProperties($workspace, $nodeAggregateId, [$property => $resolvedValue]);
        }

        $this->outputJson([
            'success' => true,
            'nodeAggregateId' => $nodeAggregateId,
            'property' => $property,
            'newValue' => $value,
        ]);
    }

    /**
     * Move a node (provide exactly one of newParentId, insertBeforeId, or insertAfterId)
     */
    public function moveNodeCommand(string $nodeAggregateId, string $newParentId = '', string $insertBeforeId = '', string $insertAfterId = '', string $workspace = 'mcp'): void
    {
        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);

        if ($insertBeforeId !== '') {
            $this->crService->moveNode($workspace, $nodeAggregateId, newSucceedingSiblingId: $insertBeforeId);
        } elseif ($insertAfterId !== '') {
            $this->crService->moveNode($workspace, $nodeAggregateId, newPrecedingSiblingId: $insertAfterId);
        } elseif ($newParentId !== '') {
            $this->crService->moveNode($workspace, $nodeAggregateId, newParentId: $newParentId);
        } else {
            $this->outputJson(['error' => 'Provide one of: newParentId, insertBeforeId, insertAfterId']);
            $this->quit(1);
            return;
        }

        $this->outputJson(['success' => true, 'nodeAggregateId' => $nodeAggregateId]);
    }

    /**
     * Delete a node
     */
    public function deleteNodeCommand(string $nodeAggregateId, string $workspace = 'mcp'): void
    {
        $this->crService->ensureWorkspace($this->mcpWorkspaceName, $this->mcpWorkspaceTitle, $this->mcpWorkspaceDescription);
        $this->crService->removeNode($workspace, $nodeAggregateId);
        $this->outputJson(['success' => true, 'removedNodeAggregateId' => $nodeAggregateId]);
    }

    /**
     * List pending changes in a workspace
     */
    public function listPendingChangesCommand(string $workspace = 'mcp'): void
    {
        if (!$this->crService->workspaceExists($workspace)) {
            $this->outputJson(['pendingChanges' => [], 'workspace' => $workspace, 'count' => 0]);
            return;
        }
        $changes = $this->crService->getPendingChanges($workspace);
        $this->outputJson(['workspace' => $workspace, 'count' => count($changes), 'pendingChanges' => $changes]);
    }

    /**
     * Publish all pending changes from a workspace to live
     */
    public function publishChangesCommand(string $workspace = 'mcp'): void
    {
        $count = $this->crService->publishWorkspace($workspace);
        $this->outputJson(['success' => true, 'publishedNodes' => $count, 'workspace' => $workspace, 'targetWorkspace' => 'live']);
    }

    /**
     * List assets from the Media Manager
     */
    public function listAssetsCommand(string $mediaType = 'image', string $tag = '', int $limit = 50): void
    {
        // Delegate to the same logic as the HTTP controller
        // For CLI, we keep it simple and use the service directly
        $this->outputJson(['info' => 'Use the HTTP bridge for asset listing (requires AssetRepository query)']);
    }

    private function outputJson(array $data): void
    {
        $this->output->outputLine(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
