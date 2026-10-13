<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Psr\Http\Message\UriInterface;

/**
 * Multi-site support: which site a request works on, listing all sites and
 * resolving the site of a node path or the base URI of a site.
 *
 * @Flow\Scope("singleton")
 */
class SiteService
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * Site selected for the current request via selectSite(); null means the default site.
     */
    private ?Site $selectedSite = null;

    public function getDefaultSite(): ?Site
    {
        return $this->siteRepository->findDefault();
    }

    /**
     * Select the site that site-scoped operations (site node, page tree, pending changes,
     * publishing) work on. Null or an empty string selects the default site again.
     *
     * @throws \RuntimeException if no site with this node name exists
     */
    public function selectSite(?string $siteNodeName): ?Site
    {
        if ($siteNodeName === null || trim($siteNodeName) === '') {
            $this->selectedSite = null;
            return $this->getDefaultSite();
        }
        $site = $this->siteRepository->findOneByNodeName(trim($siteNodeName));
        if ($site === null) {
            throw new \RuntimeException('Site not found: ' . $siteNodeName, 1791540201);
        }
        $this->selectedSite = $site;
        return $site;
    }

    /**
     * The site selected via selectSite(), or the default site.
     */
    public function getCurrentSite(): ?Site
    {
        return $this->selectedSite ?? $this->getDefaultSite();
    }

    /**
     * Whether a site was selected explicitly for this request (as opposed to falling back to the default site).
     */
    public function hasSelectedSite(): bool
    {
        return $this->selectedSite !== null;
    }

    /**
     * All sites of the instance, online and offline.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSites(): array
    {
        $defaultNodeName = $this->getDefaultSite()?->getNodeName();

        $result = [];
        foreach ($this->siteRepository->findAll() as $site) {
            $result[] = $this->serializeSite($site, $defaultNodeName);
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSite(Site $site, ?string $defaultNodeName = null): array
    {
        $domains = [];
        foreach ($site->getDomains() as $domain) {
            $domains[] = [
                'domain' => (string)$domain,
                'active' => (bool)$domain->getActive(),
            ];
        }
        $primaryDomain = $site->getPrimaryDomain();

        return [
            'name' => $site->getName(),
            'nodeName' => (string)$site->getNodeName(),
            'online' => (bool)$site->isOnline(),
            'default' => $defaultNodeName !== null && (string)$site->getNodeName() === $defaultNodeName,
            'primaryDomain' => $primaryDomain !== null ? (string)$primaryDomain : null,
            'domains' => $domains,
        ];
    }

    /**
     * Node name of the site a node path belongs to, e.g. "azubi" for "/sites/azubi/home".
     */
    public function siteNodeNameForPath(string $nodePath): ?string
    {
        if (preg_match('#^/sites/([^/]+)(?:/|$)#', $nodePath, $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * The site a node path belongs to.
     */
    public function findSiteForPath(string $nodePath): ?Site
    {
        $nodeName = $this->siteNodeNameForPath($nodePath);
        return $nodeName !== null ? $this->siteRepository->findOneByNodeName($nodeName) : null;
    }

    /**
     * Base URI (scheme, host, port) for frontend links of a site: its primary domain,
     * falling back to $requestUri when the site has no domain. Scheme and port
     * that the domain does not define are taken from $requestUri, so a domain
     * "azubi.localhost" requested via "http://localhost:8080" becomes "http://azubi.localhost:8080".
     * A domain with its own scheme but no port uses the default port of that scheme.
     */
    public function buildSiteBaseUri(?Domain $domain, UriInterface $requestUri): UriInterface
    {
        $base = $requestUri->withPath('')->withQuery('')->withFragment('')->withUserInfo('');
        if ($domain === null || (string)$domain->getHostname() === '') {
            return $base;
        }

        $base = $base->withHost($domain->getHostname());
        $scheme = $domain->getScheme();
        if ($scheme && $scheme !== $base->getScheme()) {
            $base = $base->withScheme($scheme)->withPort(null);
        }
        if ($domain->getPort() !== null) {
            $base = $base->withPort($domain->getPort());
        }
        return $base;
    }
}
