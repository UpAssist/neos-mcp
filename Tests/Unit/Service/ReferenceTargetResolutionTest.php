<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\UnitTestCase;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

/**
 * Reference targets are validated before SetNodeReferences: they must exist, match the
 * node types the reference offers, and a single reference takes at most one target.
 */
class ReferenceTargetResolutionTest extends UnitTestCase
{
    private ContentRepositoryService $service;

    private NodeType $nodeType;

    protected function setUp(): void
    {
        $item = new NodeType(NodeTypeName::fromString('Vendor.Site:Content.Item'), [], []);
        $nodeTypesById = [
            'item-1' => $item,
            'item-2' => $item,
            'special-item' => new NodeType(
                NodeTypeName::fromString('Vendor.Site:Content.SpecialItem'),
                ['Vendor.Site:Content.Item' => $item],
                []
            ),
            'page-1' => new NodeType(NodeTypeName::fromString('Vendor.Site:Document.Page'), [], []),
        ];

        $this->service = $this->getMockBuilder(ContentRepositoryService::class)
            ->onlyMethods(['findReferenceTargetNodeType'])
            ->getMock();
        $this->service->method('findReferenceTargetNodeType')
            ->willReturnCallback(static fn (string $id): ?NodeType => $nodeTypesById[$id] ?? null);

        $this->nodeType = new NodeType(
            NodeTypeName::fromString('Vendor.Site:Content.ItemSelection'),
            [],
            [
                'references' => [
                    'itemReferences' => [
                        'ui' => ['inspector' => ['editorOptions' => ['nodeTypes' => ['Vendor.Site:Content.Item']]]],
                    ],
                    'mainItem' => [
                        'constraints' => ['maxItems' => 1],
                        'ui' => ['inspector' => ['editorOptions' => ['nodeTypes' => ['Vendor.Site:Content.Item']]]],
                    ],
                    'anything' => [],
                ],
            ]
        );
    }

    private function resolve(string $referenceName, mixed $rawValue): array
    {
        return $this->service->resolveReferenceTargets($this->nodeType, $referenceName, $rawValue, 'mcp');
    }

    /**
     * @test
     */
    public function targetsAreReturnedInTheGivenOrder(): void
    {
        self::assertSame(['item-2', 'item-1'], $this->resolve('itemReferences', ['item-2', 'item-1']));
        self::assertSame(['item-2', 'item-1'], $this->resolve('itemReferences', '["item-2","item-1"]'));
        self::assertSame(['item-2', 'item-1'], $this->resolve('itemReferences', 'item-2, item-1'));
        self::assertSame(['item-1'], $this->resolve('mainItem', 'item-1'));
    }

    /**
     * @test
     */
    public function anEmptyValueClearsTheReference(): void
    {
        self::assertSame([], $this->resolve('itemReferences', ''));
        self::assertSame([], $this->resolve('itemReferences', []));
        self::assertSame([], $this->resolve('itemReferences', '[]'));
        self::assertSame([], $this->resolve('itemReferences', null));
        self::assertSame([], $this->resolve('mainItem', ''));
    }

    /**
     * @test
     */
    public function anUnknownTargetFails(): void
    {
        $this->expectExceptionCode(1791540501);
        $this->expectExceptionMessage('Referenced node not found: missing-1');

        $this->resolve('itemReferences', ['item-1', 'missing-1']);
    }

    /**
     * @test
     */
    public function aSingleReferenceRejectsMoreThanOneTarget(): void
    {
        $this->expectExceptionCode(1791540502);

        $this->resolve('mainItem', ['item-1', 'item-2']);
    }

    /**
     * @test
     */
    public function aTargetOfAnotherNodeTypeIsRejected(): void
    {
        $this->expectExceptionCode(1791540503);
        $this->expectExceptionMessage('page-1 is a Vendor.Site:Document.Page');

        $this->resolve('itemReferences', ['item-1', 'page-1']);
    }

    /**
     * @test
     */
    public function aSubtypeOfATargetNodeTypeIsAccepted(): void
    {
        self::assertSame(['special-item'], $this->resolve('itemReferences', ['special-item']));
    }

    /**
     * @test
     */
    public function aReferenceWithoutTargetNodeTypesAcceptsAnyExistingNode(): void
    {
        self::assertSame(['page-1', 'item-1'], $this->resolve('anything', ['page-1', 'item-1']));
    }
}
