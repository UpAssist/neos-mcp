<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Aspect;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;

/**
 * Injects the 'reviewStatus' property into the minimal tree node data
 * so the Neos UI frontend can display review indicators in the document tree.
 *
 * @Flow\Aspect
 */
class ReviewStatusNodeInfoAspect
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @Flow\Around("method(Neos\Neos\Ui\Fusion\Helper\NodeInfoHelper->renderNodeWithMinimalPropertiesAndChildrenInformation())")
     */
    public function addReviewStatusToMinimalNodeInfo(JoinPointInterface $joinPoint): mixed
    {
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        if (!is_array($result)) {
            return $result;
        }

        $node = $joinPoint->getMethodArgument('node');
        if (!$node instanceof Node) {
            return $result;
        }

        $cr = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $nodeType = $cr->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType !== null && array_key_exists('reviewStatus', $nodeType->getProperties())) {
            $result['properties']['reviewStatus'] = $node->getProperty('reviewStatus') ?? 'approved';
        }

        return $result;
    }
}
