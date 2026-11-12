<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\UnitTestCase;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

/**
 * In Neos 9 references are not properties, so getPropertyType() throws for them.
 * The bridge has to recognise them through the references configuration instead.
 */
class ReferenceKindTest extends UnitTestCase
{
    private ContentRepositoryService $service;

    private NodeType $nodeType;

    protected function setUp(): void
    {
        $this->service = new ContentRepositoryService();
        $this->nodeType = new NodeType(
            NodeTypeName::fromString('Vendor.Site:Content.Teaser'),
            [],
            [
                'properties' => [
                    'title' => ['type' => 'string'],
                    'legacySingle' => ['type' => 'reference'],
                    'legacyMulti' => ['type' => 'references'],
                ],
                'references' => [
                    'single' => [
                        'constraints' => ['maxItems' => 1],
                        'ui' => [
                            'label' => 'Contact',
                            'inspector' => ['editorOptions' => ['nodeTypes' => ['Vendor.Site:Content.Person']]],
                        ],
                    ],
                    'multi' => [],
                    '_internal' => [],
                    'limited' => ['constraints' => ['maxItems' => 3]],
                ],
            ]
        );
    }

    /**
     * @test
     */
    public function legacyAndDeclaredSingleReferencesAreReference(): void
    {
        self::assertSame('reference', $this->service->getReferenceKind($this->nodeType, 'legacySingle'));
        self::assertSame('reference', $this->service->getReferenceKind($this->nodeType, 'single'));
    }

    /**
     * @test
     */
    public function legacyAndDeclaredMultiReferencesAreReferences(): void
    {
        self::assertSame('references', $this->service->getReferenceKind($this->nodeType, 'legacyMulti'));
        self::assertSame('references', $this->service->getReferenceKind($this->nodeType, 'multi'));
        self::assertSame('references', $this->service->getReferenceKind($this->nodeType, 'limited'));
    }

    /**
     * @test
     */
    public function regularAndUnknownPropertiesAreNotReferences(): void
    {
        self::assertNull($this->service->getReferenceKind($this->nodeType, 'title'));
        self::assertNull($this->service->getReferenceKind($this->nodeType, 'doesNotExist'));
    }

    /**
     * @test
     */
    public function referencesAreDescribedWithKindLabelAndTargetNodeTypes(): void
    {
        $references = $this->service->describeReferences($this->nodeType);

        self::assertSame(
            ['type' => 'reference', 'label' => 'Contact', 'targetNodeTypes' => ['Vendor.Site:Content.Person']],
            $references['single']
        );
        self::assertSame(
            ['type' => 'references', 'label' => 'multi', 'targetNodeTypes' => null],
            $references['multi']
        );
        self::assertArrayHasKey('legacyMulti', $references);
        self::assertArrayNotHasKey('title', $references);
        // Internal references (underscore prefix) are neither described nor serialized.
        self::assertArrayNotHasKey('_internal', $references);
    }
}
