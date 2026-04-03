<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Listens to McpBridgeController::nodeMutated signals and marks
 * the closest Document ancestor as needing review.
 *
 * Only acts when the Document node type has a 'reviewStatus' property,
 * i.e. when the site has opted in via the UpAssist.Neos.Mcp:Mixin.ReviewStatus mixin.
 *
 * In Neos 9, nodes are immutable read models. Property changes are applied
 * via ContentRepository commands (SetNodeProperties).
 *
 * @Flow\Scope("singleton")
 */
class ReviewStatusService
{
    private const MAX_CHANGELOG_ENTRIES = 50;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Inject
     * @var ContentRepositoryService
     */
    protected $crService;

    /**
     * Slot: called when a content node is mutated via MCP.
     */
    public function handleNodeMutated(?Node $node, string $changeDescription): void
    {
        if ($node === null) {
            return;
        }

        $documentNode = $this->crService->findClosestDocument(
            $node->aggregateId,
            $node->workspaceName->value
        );

        if ($documentNode === null) {
            return;
        }

        $cr = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $cr->getNodeTypeManager()->getNodeType($documentNode->nodeTypeName);
        if ($nodeType === null || !array_key_exists('reviewStatus', $nodeType->getProperties())) {
            return;
        }

        $now = new \DateTime();
        $entry = $this->buildChangelogEntry($node, $changeDescription, $now);

        $existingJson = $documentNode->getProperty('reviewChangelog') ?? '[]';
        $entries = json_decode($existingJson, true);
        if (!is_array($entries)) {
            $entries = [];
        }

        array_unshift($entries, $entry);
        if (count($entries) > self::MAX_CHANGELOG_ENTRIES) {
            $entries = array_slice($entries, 0, self::MAX_CHANGELOG_ENTRIES);
        }

        $this->crService->setNodeProperties(
            $node->workspaceName->value,
            $documentNode->aggregateId->value,
            [
                'reviewStatus' => 'needsReview',
                'reviewChangelog' => json_encode($entries, JSON_UNESCAPED_UNICODE),
                'reviewLastChangedAt' => $now,
            ]
        );
    }

    private function buildChangelogEntry(Node $node, string $changeDescription, \DateTime $date): array
    {
        $entry = [
            'date' => $date->format('d-m-Y H:i'),
        ];

        if (preg_match("/Property '([^']+)' changed/", $changeDescription, $matches)) {
            $propertyName = $matches[1];
            $entry['type'] = 'propertyChanged';
            $entry['propertyName'] = $propertyName;
            $entry['labelId'] = $this->resolvePropertyLabelId($node, $propertyName);
        } elseif ($changeDescription === 'New element added') {
            $entry['type'] = 'elementAdded';
        } elseif ($changeDescription === 'Element moved') {
            $entry['type'] = 'elementMoved';
        } elseif ($changeDescription === 'Element removed') {
            $entry['type'] = 'elementRemoved';
        } else {
            $entry['type'] = 'unknown';
            $entry['description'] = $changeDescription;
        }

        return $entry;
    }

    private function resolvePropertyLabelId(Node $node, string $propertyName): ?string
    {
        $cr = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return null;
        }

        $labelId = $nodeType->getConfiguration('properties.' . $propertyName . '.ui.label');

        if ($labelId !== null && is_string($labelId) && str_contains($labelId, ':')) {
            return $labelId;
        }

        return null;
    }
}
