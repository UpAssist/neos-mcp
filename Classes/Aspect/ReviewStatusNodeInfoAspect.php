<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Aspect;

use Neos\ContentRepository\Domain\Model\NodeInterface;
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
     * @Flow\Around("method(Neos\Neos\Ui\Fusion\Helper\NodeInfoHelper->renderNodeWithMinimalPropertiesAndChildrenInformation())")
     */
    public function addReviewStatusToMinimalNodeInfo(JoinPointInterface $joinPoint): mixed
    {
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        if (!is_array($result)) {
            return $result;
        }

        $node = $joinPoint->getMethodArgument('node');
        if (!$node instanceof NodeInterface) {
            return $result;
        }

        if (array_key_exists('reviewStatus', $node->getNodeType()->getProperties())) {
            $result['properties']['reviewStatus'] = $node->getProperty('reviewStatus') ?? 'approved';
        }

        return $result;
    }
}
