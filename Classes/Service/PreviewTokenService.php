<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class PreviewTokenService
{
    private ?string $activeWorkspace = null;

    public function setActiveWorkspace(string $workspace): void
    {
        $this->activeWorkspace = $workspace;
    }

    public function getActiveWorkspace(): ?string
    {
        return $this->activeWorkspace;
    }

    public function hasActivePreview(): bool
    {
        return $this->activeWorkspace !== null;
    }

    public function clear(): void
    {
        $this->activeWorkspace = null;
    }
}
