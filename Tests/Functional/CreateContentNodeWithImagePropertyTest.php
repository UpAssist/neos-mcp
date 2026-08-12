<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Functional;

use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Service\PublishingService;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use UpAssist\Neos\Mcp\Service\NodePropertyResolver;

/**
 * Regression test for the bug described in NOTES-mcp-create-property-bug.md:
 * creating a content node with an image property in the same call must
 * produce a node whose property is a resolved Image object (matching what
 * updateNodePropertyAction has always produced), not the raw
 * {"__type":"asset","identifier":"..."} payload — which corrupts the
 * property and only surfaces as a 500 at publish time.
 *
 * Exercises the same NodeTemplate/NodeTypeManager/PublishingService APIs
 * McpBridgeController::createContentNodeAction uses, via NodePropertyResolver
 * directly, since ActionController actions require a full HTTP dispatch to
 * exercise through the controller itself.
 */
class CreateContentNodeWithImagePropertyTest extends FunctionalTestCase
{
    /**
     * @var boolean
     */
    protected static $testablePersistenceEnabled = true;

    private ContextFactoryInterface $contextFactory;

    private WorkspaceRepository $workspaceRepository;

    private NodeTypeManager $nodeTypeManager;

    private PublishingService $publishingService;

    private AssetRepository $assetRepository;

    private ResourceManager $resourceManager;

    private NodePropertyResolver $nodePropertyResolver;

    public function setUp(): void
    {
        parent::setUp();

        $this->contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
        $this->workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->publishingService = $this->objectManager->get(PublishingService::class);
        $this->assetRepository = $this->objectManager->get(AssetRepository::class);
        $this->resourceManager = $this->objectManager->get(ResourceManager::class);
        $this->nodePropertyResolver = $this->objectManager->get(NodePropertyResolver::class);
    }

    /**
     * @test
     */
    public function creatingContentNodeWithImagePropertyProducesAResolvedAssetThatPublishesCleanly(): void
    {
        $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
        if ($liveWorkspace === null) {
            $liveWorkspace = new Workspace('live');
            $this->workspaceRepository->add($liveWorkspace);
        }
        $mcpWorkspace = new Workspace('mcp-test', $liveWorkspace);
        $this->workspaceRepository->add($mcpWorkspace);
        $this->persistenceManager->persistAll();

        $liveContext = $this->contextFactory->create(['workspaceName' => 'live']);

        $resource = $this->resourceManager->importResource(
            __DIR__ . '/Fixtures/Resources/test-image.jpg'
        );
        $image = new Image($resource);
        $this->assetRepository->add($image);
        $this->persistenceManager->persistAll();
        $imageIdentifier = $this->persistenceManager->getIdentifierByObject($image);

        $mcpContext = $this->contextFactory->create(['workspaceName' => 'mcp-test']);
        $parentNode = $mcpContext->getRootNode();

        $nodeTypeObject = $this->nodeTypeManager->getNodeType('UpAssist.Neos.Mcp:Test.ContentWithImage');
        $template = new NodeTemplate();
        $template->setNodeType($nodeTypeObject);
        $template->setName('test-content-with-image');

        $properties = [
            'title' => 'Hello with image',
            'image' => ['__type' => 'asset', 'identifier' => $imageIdentifier],
        ];
        foreach ($properties as $key => $value) {
            $propertyType = $nodeTypeObject->getPropertyType($key);
            $resolvedValue = $this->nodePropertyResolver->resolve($mcpContext, $propertyType, $value);
            $template->setProperty($key, $resolvedValue);
        }

        $newNode = $parentNode->createNodeFromTemplate($template);
        $this->persistenceManager->persistAll();

        self::assertInstanceOf(
            \Neos\Media\Domain\Model\ImageInterface::class,
            $newNode->getProperty('image'),
            'image property must be a resolved Image object, not the raw {"__type":"asset",...} payload'
        );

        $workspace = $mcpContext->getWorkspace();
        $unpublished = $this->publishingService->getUnpublishedNodes($workspace);
        self::assertNotEmpty($unpublished, 'the created node must show up as unpublished before we can test publishing it');

        $this->publishingService->publishNodes($unpublished);
        $this->persistenceManager->persistAll();

        self::assertEmpty(
            $this->publishingService->getUnpublishedNodes($workspace),
            'publishing must succeed and leave no unpublished nodes behind'
        );

        $liveNode = $liveContext->getNode('/test-content-with-image');
        self::assertNotNull($liveNode, 'node must exist in the live workspace after publish');
        self::assertInstanceOf(\Neos\Media\Domain\Model\ImageInterface::class, $liveNode->getProperty('image'));
    }
}
