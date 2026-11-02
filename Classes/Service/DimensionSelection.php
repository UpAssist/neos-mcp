<?php
declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Service;

/**
 * Pure logic around the "dimensions" request argument, working on the preset configuration of
 * the content dimensions (dimension => ['defaultPreset' => ..., 'presets' => [name => ['values' =>
 * [...], 'uriSegment' => ..., 'label' => ...]]]). A selection is a map of dimension name => preset
 * name; any number of dimensions can be combined.
 */
class DimensionSelection
{
    /**
     * @param array<string, array<string, mixed>> $allPresets as returned by ContentDimensionPresetSourceInterface::getAllPresets()
     */
    public function __construct(private readonly array $allPresets)
    {
    }

    /**
     * The default preset of every dimension.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->allPresets as $dimension => $config) {
            $defaults[$dimension] = (string)$config['defaultPreset'];
        }
        return $defaults;
    }

    /**
     * Resolves the "dimensions" argument (an array, or a JSON object string such as
     * {"language":"nl","country":"be"}) to dimension => preset. Dimensions that are left out get
     * their default preset. Returns null when nothing was requested.
     *
     * @return array<string, string>|null
     * @throws InvalidDimensionsException
     */
    public function parse(mixed $raw): ?array
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
        if ($raw === []) {
            return null;
        }

        foreach ($raw as $dimension => $preset) {
            if (!is_string($dimension) || !isset($this->allPresets[$dimension])) {
                throw new InvalidDimensionsException(
                    sprintf('Unknown dimension "%s". Available: %s', (string)$dimension, implode(', ', array_keys($this->allPresets))),
                    1791540403
                );
            }
            if (!is_string($preset) || !isset($this->allPresets[$dimension]['presets'][$preset])) {
                throw new InvalidDimensionsException(
                    sprintf(
                        'Unknown value "%s" for dimension "%s". Available: %s',
                        is_scalar($preset) ? (string)$preset : gettype($preset),
                        $dimension,
                        implode(', ', array_keys($this->allPresets[$dimension]['presets']))
                    ),
                    1791540404
                );
            }
        }

        return array_merge($this->defaults(), $raw);
    }

    /**
     * Context dimensions (the value list of each preset, including its fallbacks) and target
     * dimensions (the single value new variants are created in) for a selection.
     *
     * @param array<string, string> $selection
     * @return array{dimensions: array<string, array<int, string>>, targetDimensions: array<string, string>}
     */
    public function contextConfiguration(array $selection): array
    {
        $dimensions = [];
        $targetDimensions = [];
        foreach ($selection as $dimension => $presetName) {
            $values = array_values($this->allPresets[$dimension]['presets'][$presetName]['values']);
            $dimensions[$dimension] = $values;
            $targetDimensions[$dimension] = $values[0];
        }
        return ['dimensions' => $dimensions, 'targetDimensions' => $targetDimensions];
    }

    /**
     * Whether a node stored in $storedValues (dimension => the value it is stored in) lives in
     * exactly the selected dimensions, as opposed to being visible there through a fallback.
     *
     * @param array<string, string> $selection
     * @param array<string, string> $storedValues
     */
    public function isStoredIn(array $storedValues, array $selection): bool
    {
        foreach ($this->contextConfiguration($selection)['targetDimensions'] as $dimension => $value) {
            if (($storedValues[$dimension] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * The URI path segment of a selection: the URI segments of the presets, in dimension order,
     * joined with "_" (e.g. "nl_be"). Presets without a segment are left out.
     *
     * @param array<string, string> $selection
     */
    public function uriSegment(array $selection): string
    {
        $segments = [];
        foreach ($selection as $dimension => $presetName) {
            $segment = (string)($this->allPresets[$dimension]['presets'][$presetName]['uriSegment'] ?? '');
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        return implode('_', $segments);
    }

    /**
     * Available dimensions: name, default and the values with their fallback chains.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(): array
    {
        $result = [];
        foreach ($this->allPresets as $name => $config) {
            $values = [];
            foreach ($config['presets'] as $presetName => $preset) {
                $values[] = [
                    'value' => $presetName,
                    'label' => $preset['label'] ?? $presetName,
                    'fallback' => array_slice(array_values($preset['values']), 1),
                ];
            }
            $result[] = [
                'name' => $name,
                'default' => $config['defaultPreset'],
                'values' => $values,
            ];
        }
        return $result;
    }
}
