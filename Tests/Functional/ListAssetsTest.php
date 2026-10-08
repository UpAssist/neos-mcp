<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Functional;

use Neos\Flow\Tests\FunctionalTestCase;

/**
 * GET /neos/mcp/listAssets: filtering must happen in the query, so that total, limit and offset
 * all describe the filtered result set.
 */
class ListAssetsTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    protected $testableHttpEnabled = true;

    private const TOKEN = 'functional-test-token';

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function server(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_TYPE' => 'application/json'];
    }

    private function uploadPng(string $filename, array $tags = [], string $suffix = ''): void
    {
        $content = base64_encode(base64_decode(self::PNG_BASE64) . random_bytes(8));
        $response = $this->browser->request(
            'http://localhost/neos/mcp/uploadAsset',
            'POST',
            [],
            [],
            $this->server(),
            json_encode(['filename' => $filename, 'content' => $content, 'tags' => $tags], JSON_THROW_ON_ERROR)
        );
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    /** @return array<string, mixed> */
    private function list(array $query): array
    {
        $response = $this->browser->request('http://localhost/neos/mcp/listAssets?' . http_build_query($query), 'GET', [], [], $this->server());
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return json_decode((string)$response->getBody(), true);
    }

    /**
     * @test
     */
    public function tagFilterAppliesToTotalAndPagination(): void
    {
        $tag = 'list-tag-' . bin2hex(random_bytes(3));
        foreach (['t1', 't2', 't3'] as $name) {
            $this->uploadPng($name . '.png', [$tag]);
        }
        foreach (['u1', 'u2', 'u3', 'u4', 'u5'] as $name) {
            $this->uploadPng($name . '.png');
        }

        $page1 = $this->list(['mediaType' => 'image', 'tag' => $tag, 'limit' => 2, 'offset' => 0]);
        $page2 = $this->list(['mediaType' => 'image', 'tag' => $tag, 'limit' => 2, 'offset' => 2]);

        self::assertSame(3, $page1['total'], 'total must count only the assets that match the tag');
        self::assertCount(2, $page1['assets']);
        self::assertCount(1, $page2['assets']);

        $filenames = array_merge(array_column($page1['assets'], 'filename'), array_column($page2['assets'], 'filename'));
        sort($filenames);
        self::assertSame(['t1.png', 't2.png', 't3.png'], $filenames, 'every tagged asset is reachable by paging, none of the untagged ones');
    }

    /**
     * @test
     */
    public function unknownTagYieldsAnEmptyResult(): void
    {
        $this->uploadPng('someone.png');
        $result = $this->list(['mediaType' => 'image', 'tag' => 'no-such-tag-' . bin2hex(random_bytes(3))]);
        self::assertSame([], $result['assets']);
        self::assertSame(0, $result['total']);
    }

    /**
     * @test
     */
    public function mediaTypeFilterStillSeparatesImagesFromDocuments(): void
    {
        $tag = 'mixed-tag-' . bin2hex(random_bytes(3));
        $this->uploadPng('pic.png', [$tag]);
        $this->browser->request('http://localhost/neos/mcp/uploadAsset', 'POST', [], [], $this->server(), json_encode([
            'filename' => 'doc.txt', 'content' => base64_encode('doc ' . bin2hex(random_bytes(6))), 'tags' => [$tag],
        ], JSON_THROW_ON_ERROR));

        $images = $this->list(['mediaType' => 'image', 'tag' => $tag]);
        $texts = $this->list(['mediaType' => 'text', 'tag' => $tag]);
        $all = $this->list(['mediaType' => '', 'tag' => $tag]);

        self::assertSame(['pic.png'], array_column($images['assets'], 'filename'));
        self::assertSame(['doc.txt'], array_column($texts['assets'], 'filename'));
        self::assertSame(2, $all['total']);
    }
}
