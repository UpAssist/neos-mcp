<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Neos\Flow\Tests\UnitTestCase;
use UpAssist\Neos\Mcp\Service\DimensionSelection;
use UpAssist\Neos\Mcp\Service\InvalidDimensionsException;

/**
 * The "dimensions" request argument: parsing and validation, the resulting context
 * configuration, URI segments and the check whether a node is stored in the selected dimensions.
 * Uses two dimensions throughout, since any number of dimensions can be combined.
 */
class DimensionSelectionTest extends UnitTestCase
{
    private DimensionSelection $selection;

    protected function setUp(): void
    {
        $this->selection = new DimensionSelection([
            'language' => [
                'defaultPreset' => 'en_US',
                'presets' => [
                    'en_US' => ['label' => 'English (US)', 'values' => ['en_US'], 'uriSegment' => 'en'],
                    'en_UK' => ['label' => 'English (UK)', 'values' => ['en_UK', 'en_US'], 'uriSegment' => 'uk'],
                    'nl' => ['label' => 'Nederlands', 'values' => ['nl'], 'uriSegment' => 'nl'],
                ],
            ],
            'country' => [
                'defaultPreset' => 'nl',
                'presets' => [
                    'nl' => ['label' => 'Nederland', 'values' => ['nl'], 'uriSegment' => 'nl'],
                    'be' => ['label' => 'België', 'values' => ['be', 'nl'], 'uriSegment' => 'be'],
                ],
            ],
        ]);
    }

    /**
     * @test
     */
    public function defaultsAreTheDefaultPresetOfEveryDimension(): void
    {
        self::assertSame(['language' => 'en_US', 'country' => 'nl'], $this->selection->defaults());
    }

    /**
     * @test
     */
    public function nothingRequestedMeansNoSelection(): void
    {
        self::assertNull($this->selection->parse(''));
        self::assertNull($this->selection->parse('  '));
        self::assertNull($this->selection->parse([]));
        self::assertNull($this->selection->parse('{}'));
    }

    /**
     * @test
     */
    public function dimensionsLeftOutGetTheirDefaultPreset(): void
    {
        self::assertSame(['language' => 'nl', 'country' => 'nl'], $this->selection->parse(['language' => 'nl']));
        self::assertSame(['language' => 'en_US', 'country' => 'be'], $this->selection->parse('{"country":"be"}'));
    }

    /**
     * @test
     */
    public function severalDimensionsCanBeCombinedAsArrayOrJson(): void
    {
        $expected = ['language' => 'en_UK', 'country' => 'be'];
        self::assertSame($expected, $this->selection->parse(['language' => 'en_UK', 'country' => 'be']));
        self::assertSame($expected, $this->selection->parse('{"language":"en_UK","country":"be"}'));
    }

    /**
     * @test
     * @dataProvider invalidArguments
     */
    public function invalidArgumentsAreRejected(mixed $raw, string $messagePart): void
    {
        $this->expectException(InvalidDimensionsException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');
        $this->selection->parse($raw);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'not json' => ['zzz', 'JSON object'],
            'json scalar' => ['"nl"', 'JSON object'],
            'scalar' => [42, 'must be an object'],
            'unknown dimension' => [['region' => 'x'], 'Unknown dimension "region". Available: language, country'],
            'unknown value' => [['language' => 'fr'], 'Unknown value "fr" for dimension "language". Available: en_US, en_UK, nl'],
            'unknown value in second dimension' => [['language' => 'nl', 'country' => 'xx'], 'Unknown value "xx" for dimension "country"'],
            'non-string value' => [['language' => ['nl']], 'Unknown value "array"'],
        ];
    }

    /**
     * @test
     */
    public function theContextHoldsTheFallbackListsAndTheTargetIsTheFirstValue(): void
    {
        $configuration = $this->selection->contextConfiguration(['language' => 'en_UK', 'country' => 'be']);

        self::assertSame(['language' => ['en_UK', 'en_US'], 'country' => ['be', 'nl']], $configuration['dimensions']);
        self::assertSame(['language' => 'en_UK', 'country' => 'be'], $configuration['targetDimensions']);
    }

    /**
     * @test
     */
    public function aNodeIsStoredInTheSelectionOnlyWhenEveryDimensionMatchesExactly(): void
    {
        $selection = ['language' => 'en_UK', 'country' => 'be'];

        self::assertTrue($this->selection->isStoredIn(['language' => 'en_UK', 'country' => 'be'], $selection));
        // visible through the language fallback
        self::assertFalse($this->selection->isStoredIn(['language' => 'en_US', 'country' => 'be'], $selection));
        // visible through the country fallback
        self::assertFalse($this->selection->isStoredIn(['language' => 'en_UK', 'country' => 'nl'], $selection));
        // missing dimension value
        self::assertFalse($this->selection->isStoredIn(['language' => 'en_UK'], $selection));
    }

    /**
     * @test
     */
    public function theUriSegmentJoinsTheSegmentsOfAllDimensions(): void
    {
        self::assertSame('uk_be', $this->selection->uriSegment(['language' => 'en_UK', 'country' => 'be']));
        self::assertSame('nl_nl', $this->selection->uriSegment(['language' => 'nl', 'country' => 'nl']));
    }

    /**
     * @test
     */
    public function presetsWithoutAUriSegmentAreLeftOutOfTheSegment(): void
    {
        $selection = new DimensionSelection([
            'language' => ['defaultPreset' => 'en', 'presets' => ['en' => ['values' => ['en'], 'uriSegment' => '']]],
            'country' => ['defaultPreset' => 'nl', 'presets' => ['nl' => ['values' => ['nl'], 'uriSegment' => 'nl']]],
        ]);

        self::assertSame('nl', $selection->uriSegment(['language' => 'en', 'country' => 'nl']));
        self::assertSame('', $selection->uriSegment([]));
    }

    /**
     * @test
     */
    public function describingListsDimensionsDefaultsAndFallbackChains(): void
    {
        $described = $this->selection->describe();

        self::assertSame(['language', 'country'], array_column($described, 'name'));
        self::assertSame('en_US', $described[0]['default']);
        self::assertSame('nl', $described[1]['default']);

        $byValue = array_column($described[0]['values'], null, 'value');
        self::assertSame([], $byValue['en_US']['fallback']);
        self::assertSame(['en_US'], $byValue['en_UK']['fallback']);
        self::assertSame('Nederlands', $byValue['nl']['label']);
        self::assertSame(['nl'], array_column($described[1]['values'], 'fallback', 'value')['be']);
    }
}
