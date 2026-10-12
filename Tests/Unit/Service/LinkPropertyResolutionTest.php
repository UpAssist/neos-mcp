<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\Link\Link;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

/**
 * Properties of type Neos\Neos\Domain\Link\Link (LinkEditor) must be converted
 * into Link value objects, otherwise the content repository rejects the write.
 */
class LinkPropertyResolutionTest extends UnitTestCase
{
    private ContentRepositoryService $service;

    private NodeType $nodeType;

    protected function setUp(): void
    {
        $this->service = new ContentRepositoryService();
        $this->nodeType = new NodeType(
            NodeTypeName::fromString('Vendor.Site:Content.Teaser'),
            [],
            ['properties' => ['link' => ['type' => Link::class]]]
        );
    }

    private function resolve(mixed $rawValue): mixed
    {
        return $this->service->resolvePropertyValueForNodeType($this->nodeType, 'link', $rawValue);
    }

    /**
     * @test
     */
    public function plainUriStringBecomesLink(): void
    {
        $link = $this->resolve('node://5a3e67e0-5a5d-4da1-b8ee-9fe6d8e7bbb1');

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('node://5a3e67e0-5a5d-4da1-b8ee-9fe6d8e7bbb1', (string)$link->href);
        self::assertNull($link->title);
    }

    /**
     * @test
     */
    public function externalUriStringBecomesLink(): void
    {
        $link = $this->resolve('https://example.org/page');

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.org/page', (string)$link->href);
    }

    /**
     * @test
     */
    public function jsonObjectStringBecomesLinkWithAttributes(): void
    {
        $link = $this->resolve('{"href":"node://abc","title":"Zur Seite","target":"_blank","rel":["noopener"]}');

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('node://abc', (string)$link->href);
        self::assertSame('Zur Seite', $link->title);
        self::assertSame('_blank', $link->target);
        self::assertSame(['noopener'], $link->rel);
    }

    /**
     * @test
     */
    public function decodedArrayBecomesLink(): void
    {
        $link = $this->resolve(['href' => 'asset://def', 'download' => true]);

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('asset://def', (string)$link->href);
        self::assertTrue($link->download);
    }

    /**
     * @test
     */
    public function emptyValuesClearTheLink(): void
    {
        self::assertNull($this->resolve(''));
        self::assertNull($this->resolve('  '));
        self::assertNull($this->resolve('null'));
        self::assertNull($this->resolve(null));
    }

    /**
     * @test
     */
    public function linkInstanceIsPassedThrough(): void
    {
        $link = Link::fromString('node://abc');

        self::assertSame($link, $this->resolve($link));
    }

    /**
     * @test
     */
    public function objectWithoutHrefIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791540102);

        $this->resolve('{"title":"no href"}');
    }

    /**
     * @test
     */
    public function invalidJsonIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791540101);

        $this->resolve('{not json');
    }

    /**
     * @test
     */
    public function typeWithLeadingBackslashIsRecognized(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Vendor.Site:Content.Button'),
            [],
            ['properties' => ['link' => ['type' => '\\' . Link::class]]]
        );

        self::assertInstanceOf(
            Link::class,
            $this->service->resolvePropertyValueForNodeType($nodeType, 'link', 'node://abc')
        );
    }

    private function serviceWithNodeExists(bool $exists): ContentRepositoryService
    {
        $service = $this->getMockBuilder(ContentRepositoryService::class)
            ->onlyMethods(['nodeExists'])
            ->getMock();
        $service->method('nodeExists')->willReturn($exists);
        return $service;
    }

    /**
     * @test
     */
    public function nodeLinkToExistingTargetIsAcceptedWithWorkspace(): void
    {
        $service = $this->getMockBuilder(ContentRepositoryService::class)
            ->onlyMethods(['nodeExists'])
            ->getMock();
        $service->expects(self::once())
            ->method('nodeExists')
            ->with('368c0b22-47ea-4318-8f73-9063f6332000', 'mcp')
            ->willReturn(true);

        $link = $service->resolvePropertyValueForNodeType(
            $this->nodeType,
            'link',
            'node://368c0b22-47ea-4318-8f73-9063f6332000',
            'mcp'
        );

        self::assertInstanceOf(Link::class, $link);
    }

    /**
     * @test
     */
    public function nodeLinkToMissingTargetIsRejectedWithWorkspace(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791540104);

        $this->serviceWithNodeExists(false)->resolvePropertyValueForNodeType(
            $this->nodeType,
            'link',
            ['href' => 'node://does-not-exist', 'title' => 'Broken'],
            'mcp'
        );
    }

    /**
     * @test
     */
    public function nonNodeLinksAreNotCheckedAgainstTheWorkspace(): void
    {
        $service = $this->getMockBuilder(ContentRepositoryService::class)
            ->onlyMethods(['nodeExists'])
            ->getMock();
        $service->expects(self::never())->method('nodeExists');

        foreach (['https://example.org', 'asset://abc', 'mailto:info@example.org'] as $href) {
            self::assertInstanceOf(
                Link::class,
                $service->resolvePropertyValueForNodeType($this->nodeType, 'link', $href, 'mcp')
            );
        }
    }

    /**
     * @test
     */
    public function clearingALinkDoesNotCheckTheWorkspace(): void
    {
        $service = $this->getMockBuilder(ContentRepositoryService::class)
            ->onlyMethods(['nodeExists'])
            ->getMock();
        $service->expects(self::never())->method('nodeExists');

        self::assertNull($service->resolvePropertyValueForNodeType($this->nodeType, 'link', '', 'mcp'));
    }

    /**
     * @test
     */
    public function nonLinkPropertiesAreNotTouched(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Vendor.Site:Content.Text'),
            [],
            ['properties' => ['title' => ['type' => 'string']]]
        );

        self::assertSame(
            'node://abc',
            $this->service->resolvePropertyValueForNodeType($nodeType, 'title', 'node://abc')
        );
    }
}
