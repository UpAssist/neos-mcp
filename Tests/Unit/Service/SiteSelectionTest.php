<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Doctrine\Common\Collections\ArrayCollection;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use UpAssist\Neos\Mcp\Service\ContentRepositoryService;

/**
 * Multi-site support: selecting a site per request, listing all sites and
 * building frontend base URIs from a site's domain.
 */
class SiteSelectionTest extends UnitTestCase
{
    private const SITES_CONFIGURATION = [
        '*' => [
            'contentRepository' => 'default',
            'contentDimensions' => [
                'defaultDimensionSpacePoint' => ['language' => 'de'],
                'resolver' => ['factoryClassName' => 'Neos\\Neos\\FrontendRouting\\DimensionResolution\\Resolver\\AutoUriPathResolverFactory'],
            ],
        ],
    ];

    private ContentRepositoryService $service;

    private Site $mainSite;

    private Site $azubiSite;

    protected function setUp(): void
    {
        $this->mainSite = $this->createSite('site', 'Mode Jost', ['localhost', 'modejost.example.org']);
        $this->azubiSite = $this->createSite('azubi', 'Azubi', ['azubi.localhost']);

        $siteRepository = $this->createMock(SiteRepository::class);
        $siteRepository->method('findDefault')->willReturn($this->azubiSite);
        $siteRepository->method('findAll')->willReturn($this->queryResult([$this->azubiSite, $this->mainSite]));
        $siteRepository->method('findOneByNodeName')->willReturnCallback(
            fn (string $nodeName): ?Site => match ($nodeName) {
                'site' => $this->mainSite,
                'azubi' => $this->azubiSite,
                default => null,
            }
        );

        $this->service = new ContentRepositoryService();
        $this->inject($this->service, 'siteRepository', $siteRepository);
    }

    /**
     * @param array<int, object> $objects
     */
    private function queryResult(array $objects): QueryResultInterface
    {
        return new class ($objects) extends \ArrayIterator implements QueryResultInterface {
            public function getQuery(): QueryInterface
            {
                throw new \BadMethodCallException('Not needed in this test', 1791540299);
            }

            public function getFirst()
            {
                return $this->getArrayCopy()[0] ?? null;
            }

            public function toArray(): array
            {
                return $this->getArrayCopy();
            }
        };
    }

    /**
     * @param array<int, string> $hostnames
     */
    private function createSite(string $nodeName, string $name, array $hostnames): Site
    {
        $site = new Site($nodeName);
        $site->setName($name);
        $site->setState(Site::STATE_ONLINE);
        $this->inject($site, 'sitesConfiguration', self::SITES_CONFIGURATION);

        $domains = [];
        foreach ($hostnames as $hostname) {
            $domain = new Domain();
            $domain->setHostname($hostname);
            $domain->setActive(true);
            $domain->setSite($site);
            $domains[] = $domain;
        }
        $site->setDomains(new ArrayCollection($domains));
        return $site;
    }

    /**
     * @test
     */
    public function withoutSelectionTheDefaultSiteIsUsed(): void
    {
        self::assertFalse($this->service->hasSelectedSite());
        self::assertSame($this->azubiSite, $this->service->getCurrentSite());
    }

    /**
     * @test
     */
    public function selectingASiteMakesItTheCurrentSite(): void
    {
        $selected = $this->service->selectSite('site');

        self::assertSame($this->mainSite, $selected);
        self::assertSame($this->mainSite, $this->service->getCurrentSite());
        self::assertTrue($this->service->hasSelectedSite());
    }

    /**
     * @test
     */
    public function emptySelectionFallsBackToTheDefaultSite(): void
    {
        $this->service->selectSite('site');

        self::assertSame($this->azubiSite, $this->service->selectSite(''));
        self::assertFalse($this->service->hasSelectedSite());
        self::assertSame($this->azubiSite, $this->service->getCurrentSite());

        $this->service->selectSite('site');
        $this->service->selectSite(null);
        self::assertFalse($this->service->hasSelectedSite());
    }

    /**
     * @test
     */
    public function unknownSiteIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1791540201);

        $this->service->selectSite('does-not-exist');
    }

    /**
     * @test
     */
    public function listSitesReturnsAllSitesAndMarksTheDefault(): void
    {
        $sites = $this->service->listSites();

        self::assertSame(['azubi', 'site'], array_column($sites, 'nodeName'));
        self::assertSame([true, false], array_column($sites, 'default'));

        $main = $sites[1];
        self::assertSame('Mode Jost', $main['name']);
        self::assertTrue($main['online']);
        self::assertSame('localhost', $main['primaryDomain']);
        self::assertSame(['localhost', 'modejost.example.org'], array_column($main['domains'], 'domain'));
        self::assertSame('default', $main['contentRepositoryId']);
        self::assertSame(['language' => 'de'], $main['defaultDimensionSpacePoint']);
    }

    private function domain(string $hostname, ?string $scheme = null, ?int $port = null): Domain
    {
        $domain = new Domain();
        $domain->setHostname($hostname);
        $domain->setScheme($scheme);
        $domain->setPort($port);
        return $domain;
    }

    /**
     * @test
     */
    public function baseUriFallsBackToTheRequestWithoutDomain(): void
    {
        $uri = $this->service->buildSiteBaseUri(null, new Uri('http://localhost:8080/neos/mcp/getPreviewUrl?x=1'));

        self::assertSame('http://localhost:8080', (string)$uri);
    }

    /**
     * @test
     */
    public function baseUriUsesTheSiteHostAndKeepsRequestSchemeAndPort(): void
    {
        $uri = $this->service->buildSiteBaseUri(
            $this->domain('azubi.localhost'),
            new Uri('http://localhost:8080/neos/mcp/getPreviewUrl')
        );

        self::assertSame('http://azubi.localhost:8080', (string)$uri);
    }

    /**
     * @test
     */
    public function baseUriWithOtherSchemeUsesItsDefaultPort(): void
    {
        $uri = $this->service->buildSiteBaseUri(
            $this->domain('www.example.org', 'https'),
            new Uri('http://localhost:8080/neos/mcp/getPreviewUrl')
        );

        self::assertSame('https://www.example.org', (string)$uri);
    }

    /**
     * @test
     */
    public function baseUriUsesAnExplicitDomainPort(): void
    {
        $uri = $this->service->buildSiteBaseUri(
            $this->domain('staging.example.org', 'http', 8081),
            new Uri('https://bridge.example.org/neos/mcp/getPreviewUrl')
        );

        self::assertSame('http://staging.example.org:8081', (string)$uri);
    }
}
