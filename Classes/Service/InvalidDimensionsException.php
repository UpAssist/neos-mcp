<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

/**
 * The "dimensions" argument of a request cannot be used (bad format, unknown dimension or value).
 */
class InvalidDimensionsException extends \InvalidArgumentException
{
}
