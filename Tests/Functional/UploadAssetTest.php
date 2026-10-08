<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Functional;

use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;

/**
 * Exercises POST /neos/mcp/uploadAsset through the real HTTP stack (routing, auth,
 * argument mapping, persistence), so the same test runs unchanged on the Neos 8 and
 * the Neos 9 branch of the bridge.
 */
class UploadAssetTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    protected $testableHttpEnabled = true;

    private const TOKEN = 'functional-test-token';

    /** 1x1 transparent PNG */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function upload(array $payload, ?string $token = self::TOKEN): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $serverVars = [];
        foreach ($headers as $name => $value) {
            $serverVars['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $serverVars['CONTENT_TYPE'] = 'application/json';

        $response = $this->browser->request(
            'http://localhost/neos/mcp/uploadAsset',
            'POST',
            [],
            [],
            $serverVars,
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $decoded = json_decode((string)$response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /**
     * Unique content per call, so tests never collide with each other or with leftovers.
     * Appending bytes after the PNG IEND chunk keeps the file a valid image.
     */
    private function uniquePng(): string
    {
        return base64_encode(base64_decode(self::PNG_BASE64) . random_bytes(8));
    }

    /**
     * Flow's method security rejects unauthenticated calls (403) before the controller's own
     * checkAuth() can answer 401, so either status means "rejected".
     *
     * @test
     */
    public function requestsWithoutAValidTokenAreRejectedAndStoreNothing(): void
    {
        $content = $this->uniquePng();
        $sha1 = sha1(base64_decode($content));

        self::assertContains($this->upload(['filename' => 'x.png', 'content' => $content], 'wrong-token')['status'], [401, 403]);
        self::assertContains($this->upload(['filename' => 'x.png', 'content' => $content], null)['status'], [401, 403]);

        $this->persistenceManager->clearState();
        self::assertNull($this->objectManager->get(AssetRepository::class)->findOneByResourceSha1($sha1));
    }

    /**
     * @test
     */
    public function missingFilenameOrContentIsABadRequest(): void
    {
        self::assertSame(400, $this->upload(['filename' => '', 'content' => $this->uniquePng()])['status']);
        self::assertSame(400, $this->upload(['filename' => 'x.png', 'content' => ''])['status']);
    }

    /**
     * @test
     */
    public function invalidBase64IsABadRequest(): void
    {
        $result = $this->upload(['filename' => 'x.png', 'content' => '%%% not base64 %%%']);
        self::assertSame(400, $result['status']);
    }

    /**
     * @test
     */
    public function uploadingAPngCreatesAnImageAssetWithMetadata(): void
    {
        $result = $this->upload([
            'filename' => 'upload-test.png',
            'content' => $this->uniquePng(),
            'title' => 'Upload test',
            'caption' => 'A caption',
            'copyrightNotice' => '(c) UpAssist',
        ]);

        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['success']);
        self::assertFalse($result['body']['duplicate']);

        $asset = $result['body']['asset'];
        self::assertSame('upload-test.png', $asset['filename']);
        self::assertSame('image/png', $asset['mediaType']);
        self::assertSame('Image', $asset['assetType']);
        self::assertSame('Upload test', $asset['title']);
        self::assertSame('A caption', $asset['caption']);
        self::assertNotEmpty($asset['identifier']);
        self::assertNotEmpty($asset['publicUri']);

        $this->persistenceManager->clearState();
        $stored = $this->objectManager->get(AssetRepository::class)->findByIdentifier($asset['identifier']);
        self::assertNotNull($stored, 'the asset must be persisted and visible in the Media repository');
        self::assertSame('(c) UpAssist', $stored->getCopyrightNotice());
    }

    /**
     * @test
     */
    public function pathPartsInTheFilenameAreStripped(): void
    {
        $result = $this->upload(['filename' => '../../etc/evil.png', 'content' => $this->uniquePng()]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('evil.png', $result['body']['asset']['filename']);
    }

    /**
     * @test
     */
    public function nonImageFilesBecomeDocumentAssets(): void
    {
        $result = $this->upload([
            'filename' => 'notes.txt',
            'content' => base64_encode('hello world ' . bin2hex(random_bytes(6))),
        ]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertSame('Document', $result['body']['asset']['assetType']);
        self::assertSame('text/plain', $result['body']['asset']['mediaType']);
    }

    /**
     * @test
     */
    public function identicalContentReturnsTheExistingAssetUnlessDuplicatesAreAllowed(): void
    {
        $content = $this->uniquePng();

        $first = $this->upload(['filename' => 'dup.png', 'content' => $content]);
        self::assertFalse($first['body']['duplicate']);

        $second = $this->upload(['filename' => 'dup.png', 'content' => $content]);
        self::assertSame(200, $second['status']);
        self::assertTrue($second['body']['duplicate']);
        self::assertSame($first['body']['asset']['identifier'], $second['body']['asset']['identifier']);

        $third = $this->upload(['filename' => 'dup.png', 'content' => $content, 'allowDuplicate' => true]);
        self::assertSame(200, $third['status'], json_encode($third['body']));
        self::assertFalse($third['body']['duplicate']);
        self::assertNotSame($first['body']['asset']['identifier'], $third['body']['asset']['identifier']);
    }

    /**
     * @test
     */
    public function missingTagsAndCollectionsAreCreatedAndAssigned(): void
    {
        $result = $this->upload([
            'filename' => 'tagged.png',
            'content' => $this->uniquePng(),
            'tags' => ['mcp-test-tag', ' mcp-test-tag ', 'second-tag'],
            'assetCollections' => ['MCP test collection'],
        ]);
        self::assertSame(200, $result['status'], json_encode($result['body']));

        $asset = $result['body']['asset'];
        self::assertEqualsCanonicalizing(['mcp-test-tag', 'second-tag'], $asset['tags'], 'tags are trimmed and de-duplicated');
        self::assertSame(['MCP test collection'], $asset['collections']);

        $this->persistenceManager->clearState();
        self::assertNotNull($this->objectManager->get(TagRepository::class)->findOneByLabel('mcp-test-tag'));
        self::assertNotNull($this->objectManager->get(AssetCollectionRepository::class)->findOneByTitle('MCP test collection'));
    }

    /**
     * @test
     */
    public function existingTagsAreReusedInsteadOfDuplicated(): void
    {
        $this->upload(['filename' => 'a.png', 'content' => $this->uniquePng(), 'tags' => ['shared-tag']]);
        $this->upload(['filename' => 'b.png', 'content' => $this->uniquePng(), 'tags' => ['shared-tag']]);

        $this->persistenceManager->clearState();
        $tags = $this->objectManager->get(TagRepository::class)->findByLabel('shared-tag');
        self::assertCount(1, $tags);
    }

    /**
     * @test
     */
    public function uploadedAssetIsVisibleThroughListAssets(): void
    {
        $upload = $this->upload(['filename' => 'listed.png', 'content' => $this->uniquePng(), 'tags' => ['listed-tag']]);
        self::assertSame(200, $upload['status'], json_encode($upload['body']));

        $response = $this->browser->request(
            'http://localhost/neos/mcp/listAssets?mediaType=image&tag=listed-tag',
            'GET',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN, 'HTTP_ACCEPT' => 'application/json']
        );
        self::assertSame(200, $response->getStatusCode());
        $identifiers = array_column(json_decode((string)$response->getBody(), true)['assets'] ?? [], 'identifier');
        self::assertContains($upload['body']['asset']['identifier'], $identifiers);
    }
}
