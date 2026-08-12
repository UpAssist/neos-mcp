<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Repository\AssetRepository;

/**
 * Resolves raw MCP property values (asset identifiers, node references, date
 * strings, booleans passed as strings, ...) into the typed values Neos'
 * NodeInterface::setProperty() expects, based on the NodeType's declared
 * property type.
 *
 * Shared between the create and update node-property write paths so both
 * apply the same resolution instead of the create path writing raw,
 * unresolved values (see NOTES-mcp-create-property-bug.md).
 *
 * @Flow\Scope("singleton")
 */
class NodePropertyResolver
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * Resolve a single property value for the given property type.
     *
     * @throws NodePropertyResolutionException if an asset/reference cannot be found or a date is invalid
     */
    public function resolve(Context $context, ?string $propertyType, mixed $value): mixed
    {
        $resolvedValue = $value;

        if ($propertyType !== null && (str_contains($propertyType, 'Image') || str_contains($propertyType, 'Asset'))) {
            $resolvedValue = $this->resolveAsset($resolvedValue);
        }

        if (is_string($resolvedValue) && in_array(strtolower($resolvedValue), ['true', 'false'], true)) {
            $resolvedValue = strtolower($resolvedValue) === 'true';
        }

        if ($propertyType === 'reference' && is_string($resolvedValue) && $resolvedValue !== '') {
            $this->assertNodeExists($context, $resolvedValue);
        }

        if ($propertyType === 'references') {
            $resolvedValue = $this->resolveReferences($context, $resolvedValue);
        }

        if ($propertyType === 'DateTime' && is_string($resolvedValue) && $resolvedValue !== '') {
            $resolvedValue = $this->resolveDateTime($resolvedValue);
        }

        if ($propertyType === 'array' && is_string($resolvedValue)) {
            $decoded = json_decode($resolvedValue, true);
            if (is_array($decoded)) {
                $resolvedValue = $decoded;
            }
        }

        return $resolvedValue;
    }

    private function resolveAsset(mixed $value): object
    {
        $assetIdentifier = $value;
        if (is_string($value) && str_starts_with(trim($value), '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && isset($decoded['identifier'])) {
                $assetIdentifier = $decoded['identifier'];
            }
        } elseif (is_array($value) && isset($value['identifier'])) {
            // Create-path properties come from a decoded JSON body, so the
            // {"__type":"asset","identifier":"..."} object arrives as a PHP
            // array already, not a JSON string.
            $assetIdentifier = $value['identifier'];
        }

        $asset = $this->assetRepository->findByIdentifier($assetIdentifier);
        if ($asset === null) {
            throw new NodePropertyResolutionException('Asset not found: ' . $assetIdentifier, 404);
        }

        return $asset;
    }

    private function assertNodeExists(Context $context, string $identifier): void
    {
        if ($context->getNodeByIdentifier($identifier) === null) {
            throw new NodePropertyResolutionException('Referenced node not found: ' . $identifier, 404);
        }
    }

    private function resolveReferences(Context $context, mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $identifiers = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
        } elseif (is_array($value)) {
            $identifiers = $value;
        } else {
            $identifiers = [];
        }

        $validated = [];
        foreach ($identifiers as $identifier) {
            if ($context->getNodeByIdentifier($identifier) !== null) {
                $validated[] = $identifier;
            }
        }

        return $validated;
    }

    private function resolveDateTime(string $value): \DateTime
    {
        try {
            return new \DateTime($value);
        } catch (\Exception $e) {
            throw new NodePropertyResolutionException('Invalid date format: ' . $value, 400);
        }
    }
}
