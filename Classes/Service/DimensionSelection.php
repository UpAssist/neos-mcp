<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

/**
 * Pure logic around the "dimensions" request argument and dimension space point coordinates.
 * Coordinates are maps of dimension name => value; any number of dimensions can be combined.
 *
 * The available dimensions are described as
 * [dimension => ['values' => [value => ['label' => string|null, 'generalization' => string|null]]]].
 */
class DimensionSelection
{
    /**
     * @param array<string, array{values: array<string, array{label?: string|null, generalization?: string|null}>}> $availableDimensions
     */
    public function __construct(private readonly array $availableDimensions)
    {
    }

    /**
     * Parses the "dimensions" argument: an array, or a JSON object string such as
     * {"language":"nl","country":"be"}. Returns null when nothing was requested.
     *
     * @return array<string, string>|null
     * @throws InvalidDimensionsException
     */
    public static function parseArgument(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new InvalidDimensionsException('dimensions must be a JSON object such as {"language":"nl"}', 1791540401);
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            throw new InvalidDimensionsException('dimensions must be an object such as {"language":"nl"}', 1791540402);
        }
        return $raw === [] ? null : $raw;
    }

    /**
     * Validates requested dimensions against the available ones and completes them with the
     * defaults (the site's default coordinates) for the dimensions that were left out.
     *
     * @param array<string, mixed> $requested
     * @param array<string, string> $defaults
     * @return array<string, string>
     * @throws InvalidDimensionsException
     */
    public function complete(array $requested, array $defaults): array
    {
        foreach ($requested as $name => $value) {
            if (!is_string($name) || !isset($this->availableDimensions[$name])) {
                throw new InvalidDimensionsException(
                    sprintf('Unknown dimension "%s". Available: %s', (string)$name, implode(', ', array_keys($this->availableDimensions))),
                    1791540403
                );
            }
            if (!is_string($value) || !isset($this->availableDimensions[$name]['values'][$value])) {
                throw new InvalidDimensionsException(
                    sprintf(
                        'Unknown value "%s" for dimension "%s". Available: %s',
                        is_scalar($value) ? (string)$value : gettype($value),
                        $name,
                        implode(', ', array_keys($this->availableDimensions[$name]['values']))
                    ),
                    1791540404
                );
            }
        }
        return array_merge($defaults, $requested);
    }

    /**
     * Available dimensions: name, default and the values with the fallback chain (the
     * generalizations a value is shown through).
     *
     * @param array<string, string> $defaults
     * @return array<int, array<string, mixed>>
     */
    public function describe(array $defaults): array
    {
        $result = [];
        foreach ($this->availableDimensions as $name => $dimension) {
            $values = [];
            foreach ($dimension['values'] as $value => $info) {
                $fallback = [];
                $current = $info['generalization'] ?? null;
                while ($current !== null && !in_array($current, $fallback, true)) {
                    $fallback[] = $current;
                    $current = $dimension['values'][$current]['generalization'] ?? null;
                }
                $values[] = [
                    'value' => (string)$value,
                    'label' => $info['label'] ?? (string)$value,
                    'fallback' => $fallback,
                ];
            }
            $result[] = ['name' => $name, 'default' => $defaults[$name] ?? null, 'values' => $values];
        }
        return $result;
    }

    /**
     * Whether two coordinates are the same dimension space point, regardless of key order.
     *
     * @param array<string, string> $a
     * @param array<string, string> $b
     */
    public static function sameCoordinates(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    /**
     * Splits pending changes into the node aggregates that can be published for the requested
     * dimensions and those that cannot. Neos publishes whole node aggregates, so a node that also
     * has pending changes in another dimension is skipped. Aggregate wide changes (dimensions
     * null) count as being in the requested dimensions.
     *
     * @param array<int, array{nodeAggregateId: string, dimensions: array<string, string>|null}> $changes
     * @param array<string, string> $requested
     * @return array{publishable: array<int, string>, skipped: array<int, array<string, mixed>>}
     */
    public static function partitionForPublishing(array $changes, array $requested): array
    {
        $byAggregate = [];
        foreach ($changes as $change) {
            $byAggregate[$change['nodeAggregateId']][] = $change['dimensions'];
        }

        $publishable = [];
        $skipped = [];
        foreach ($byAggregate as $aggregateId => $dimensionsOfChanges) {
            $others = [];
            $inRequested = false;
            foreach ($dimensionsOfChanges as $dimensions) {
                if ($dimensions === null || self::sameCoordinates($dimensions, $requested)) {
                    $inRequested = true;
                } else {
                    $others[] = $dimensions;
                }
            }
            if (!$inRequested) {
                continue;
            }
            if ($others !== []) {
                $skipped[] = [
                    'nodeAggregateId' => (string)$aggregateId,
                    'reason' => 'Also has pending changes in other dimensions; Neos publishes whole nodes. Publish without "dimensions" to publish all dimensions of this node.',
                    'otherDimensions' => $others,
                ];
                continue;
            }
            $publishable[] = (string)$aggregateId;
        }
        return ['publishable' => $publishable, 'skipped' => $skipped];
    }
}
