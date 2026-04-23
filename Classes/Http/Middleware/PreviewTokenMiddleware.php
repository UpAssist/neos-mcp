<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Http\Middleware;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
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

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getQueryParams()['_mcpPreview'] ?? null;

        if (!is_string($token) || $token === '') {
            return $handler->handle($request);
        }

        $payload = $this->previewTokensCache->get($token);
        if ($payload === false) {
            return $handler->handle($request);
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['workspace'], $data['expiresAt'])) {
            return $handler->handle($request);
        }

        try {
            $expiresAt = new \DateTimeImmutable($data['expiresAt']);
        } catch (\Exception) {
            return $handler->handle($request);
        }

        if ($expiresAt <= new \DateTimeImmutable()) {
            return $handler->handle($request);
        }

        // Valid, unexpired token. Activate the preview workspace and disable
        // security authorization checks for the downstream of THIS request only.
        // WorkspacePreviewAspect reads the active workspace from PreviewTokenService
        // to short-circuit NodeController::showAction and render the preview workspace.
        // Disabling auth checks is required so the Neos ContentRepositoryAuthProvider
        // grants read access on the non-live workspace to this anonymous caller —
        // the token itself IS the authorization.
        $this->previewTokenService->setActiveWorkspace($data['workspace']);
        try {
            return $this->securityContext->withoutAuthorizationChecks(
                fn(): ResponseInterface => $handler->handle($request)
            );
        } finally {
            $this->previewTokenService->clear();
        }
    }
}
