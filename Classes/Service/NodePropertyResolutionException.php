<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

/**
 * Thrown by NodePropertyResolver when a property value cannot be resolved
 * (e.g. an asset identifier or referenced node that does not exist, or an
 * invalid date format). Carries the HTTP status the controller should map
 * this to, so create and update actions report failures the same way.
 */
class NodePropertyResolutionException extends \Exception
{
    private int $statusCode;

    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
