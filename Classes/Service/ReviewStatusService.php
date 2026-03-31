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
 * Changelog entries are stored as JSON with translation keys so the
 * custom inspector editor can render them in the user's interface language.
 *
 * @Flow\Scope("singleton")
 */
class ReviewStatusService
{
    private const MAX_CHANGELOG_ENTRIES = 50;

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

        $documentNode->setProperty('reviewStatus', 'needsReview');
        $documentNode->setProperty('reviewChangelog', json_encode($entries, JSON_UNESCAPED_UNICODE));
        $documentNode->setProperty('reviewLastChangedAt', $now);
    }

    /**
     * Slot: called when any node property changes (including from the Neos inspector).
     * Clears the changelog when reviewStatus is set to 'approved'.
     */
    public function handleReviewStatusChanged(NodeInterface $node, string $propertyName, $oldValue, $newValue): void
    {
        if ($propertyName !== 'reviewStatus') {
            return;
        }

        if ($newValue !== 'approved') {
            return;
        }

        if (!array_key_exists('reviewChangelog', $node->getNodeType()->getProperties())) {
            return;
        }

        $currentLog = $node->getProperty('reviewChangelog');
        if ($currentLog !== null && $currentLog !== '' && $currentLog !== '[]') {
            $node->setProperty('reviewChangelog', '[]');
        }
    }

    /**
     * Builds a structured changelog entry with a translation key and label ID
     * so the inspector editor can translate it in the user's interface language.
     */
    private function buildChangelogEntry(NodeInterface $node, string $changeDescription, \DateTime $date): array
    {
        $entry = [
            'date' => $date->format('d-m-Y H:i'),
        ];

        if (preg_match("/Property '([^']+)' changed(?:\\s+on\\s+\\S+)?/", $changeDescription, $matches)) {
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

    /**
     * Gets the i18n label ID for a property from its NodeType configuration.
     * Returns null if no translatable label is configured.
     */
    private function resolvePropertyLabelId(NodeInterface $node, string $propertyName): ?string
    {
        $nodeType = $node->getNodeType();
        $labelId = $nodeType->getConfiguration('properties.' . $propertyName . '.ui.label');

        if ($labelId !== null && is_string($labelId) && str_contains($labelId, ':')) {
            return $labelId;
        }

        return null;
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
