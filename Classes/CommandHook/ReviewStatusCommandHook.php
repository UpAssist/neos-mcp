<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * CommandHook that clears the review changelog when reviewStatus is set to 'approved'.
 *
 * Replaces the Neos 8 signal/slot on Node::nodePropertyChanged which no longer exists in Neos 9.
 *
 * This hook fires AFTER a SetNodeProperties command is handled. If the command sets
 * reviewStatus to 'approved', it issues an additional SetNodeProperties to clear
 * the reviewChangelog.
 */
class ReviewStatusCommandHook implements CommandHookInterface
{
    public function __construct(
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
    ) {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        // No modification needed before handling
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        if (!$command instanceof SetNodeProperties) {
            return Commands::createEmpty();
        }

        // Check if the command sets reviewStatus to 'approved'
        $propertyValues = $command->propertyValues->values;
        if (!isset($propertyValues['reviewStatus']) || $propertyValues['reviewStatus'] !== 'approved') {
            return Commands::createEmpty();
        }

        // Verify the node type has a reviewChangelog property
        $subgraph = $this->contentGraphReadModel->getContentGraph($command->workspaceName)->getSubgraph(
            $command->originDimensionSpacePoint->toDimensionSpacePoint(),
            \Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints::withoutRestrictions()
        );

        $node = $subgraph->findNodeById($command->nodeAggregateId);
        if ($node === null) {
            return Commands::createEmpty();
        }

        $nodeType = $this->nodeTypeManager->getNodeType($node->nodeTypeName);
        if ($nodeType === null || !array_key_exists('reviewChangelog', $nodeType->getProperties())) {
            return Commands::createEmpty();
        }

        // Only clear if there's actual changelog content
        $currentLog = $node->getProperty('reviewChangelog');
        if ($currentLog === null || $currentLog === '' || $currentLog === '[]') {
            return Commands::createEmpty();
        }

        // Issue command to clear the changelog
        $clearCommand = SetNodeProperties::create(
            $command->workspaceName,
            $command->nodeAggregateId,
            $command->originDimensionSpacePoint,
            PropertyValuesToWrite::fromArray(['reviewChangelog' => '[]']),
        );

        return Commands::create($clearCommand);
    }
}
