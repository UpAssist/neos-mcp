<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Listens to McpBridgeController::nodeMutated signals and marks
 * the closest Document ancestor as needing review.
 *
 * Only acts when the Document node type has a 'reviewStatus' property,
 * i.e. when the site has opted in via the UpAssist.Neos.Mcp:Mixin.ReviewStatus mixin.
 *
 * @Flow\Scope("singleton")
 */
class ReviewStatusService
{
    private const MAX_CHANGELOG_LINES = 50;

    /**
     * Slot: called when a content node is mutated via MCP.
     */
    public function handleNodeMutated(NodeInterface $node, string $changeDescription): void
    {
        $documentNode = $this->findClosestDocument($node);
        if ($documentNode === null) {
            return;
        }

        if (!array_key_exists('reviewStatus', $documentNode->getNodeType()->getProperties())) {
            return;
        }

        $now = new \DateTime();
        $entry = $now->format('d-m-Y H:i') . ' — ' . $changeDescription;

        $existingLog = $documentNode->getProperty('reviewChangelog') ?? '';
        $newLog = $entry . "\n" . $existingLog;

        $lines = explode("\n", $newLog);
        if (count($lines) > self::MAX_CHANGELOG_LINES) {
            $lines = array_slice($lines, 0, self::MAX_CHANGELOG_LINES);
        }

        $documentNode->setProperty('reviewStatus', 'needsReview');
        $documentNode->setProperty('reviewChangelog', trim(implode("\n", $lines)));
        $documentNode->setProperty('reviewLastChangedAt', $now);
    }

    private function findClosestDocument(NodeInterface $node): ?NodeInterface
    {
        $current = $node;
        while ($current !== null) {
            if ($current->getNodeType()->isOfType('Neos.Neos:Document')) {
                return $current;
            }
            $current = $current->getParent();
        }
        return null;
    }
}
