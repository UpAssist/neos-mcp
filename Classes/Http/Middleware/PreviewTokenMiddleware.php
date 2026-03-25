<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Http\Middleware;

use Neos\Flow\Annotations as Flow;
use Neos\Cache\Frontend\StringFrontend;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use UpAssist\Neos\Mcp\Service\PreviewTokenService;

class PreviewTokenMiddleware implements MiddlewareInterface
{
    /**
     * @Flow\Inject
     * @var PreviewTokenService
     */
    protected $previewTokenService;

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $previewTokensCache;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['_mcpPreview'] ?? null;

        if ($token !== null) {
            $payload = $this->previewTokensCache->get($token);
            if ($payload !== false) {
                $data = json_decode($payload, true);
                if (is_array($data) && isset($data['workspace'])) {
                    $expiresAt = new \DateTimeImmutable($data['expiresAt']);
                    if ($expiresAt > new \DateTimeImmutable()) {
                        $this->previewTokenService->setActiveWorkspace($data['workspace']);
                    }
                }
            }
        }

        return $handler->handle($request);
    }
}
