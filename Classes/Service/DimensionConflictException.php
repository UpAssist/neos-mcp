<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

/**
 * A request cannot be carried out in the requested content dimensions, for example because
 * the node only exists in another dimension or the variant already exists. Carries the HTTP
 * status the bridge answers with.
 */
class DimensionConflictException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 409)
    {
        parent::__construct($message, 1791540301);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
