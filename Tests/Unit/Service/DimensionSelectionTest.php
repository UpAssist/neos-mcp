<?php

declare(strict_types=1);

namespace UpAssist\Neos\Mcp\Tests\Unit\Service;

use Neos\Flow\Tests\UnitTestCase;
use UpAssist\Neos\Mcp\Service\DimensionSelection;
use UpAssist\Neos\Mcp\Service\InvalidDimensionsException;

/**
 * The "dimensions" request argument and dimension space point coordinates: parsing, validation,
 * the description of the available dimensions and the split of pending changes for publishing
 * per dimension. Uses two dimensions throughout, since any number of dimensions can be combined.
 */
class DimensionSelectionTest extends UnitTestCase
{
    private DimensionSelection $selection;

    protected function setUp(): void
    {
        $this->selection = new DimensionSelection([
            'language' => [
                'values' => [
                    'en_US' => ['label' => 'English (US)', 'generalization' => null],
                    'en_GB' => ['label' => 'English (GB)', 'generalization' => 'en_US'],
                    'de' => ['label' => 'Deutsch', 'generalization' => null],
                    'nl' => ['label' => null, 'generalization' => null],
                ],
            ],
            'country' => [
                'values' => [
                    'nl' => ['label' => 'Nederland', 'generalization' => null],
                    'be' => ['label' => 'België', 'generalization' => 'nl'],
                ],
            ],
        ]);
    }

    /**
     * @test
     */
    public function nothingRequestedMeansNoSelection(): void
    {
        self::assertNull(DimensionSelection::parseArgument(''));
        self::assertNull(DimensionSelection::parseArgument('  '));
        self::assertNull(DimensionSelection::parseArgument([]));
        self::assertNull(DimensionSelection::parseArgument('{}'));
    }

    /**
     * @test
     */
    public function theArgumentCanBeAnArrayOrAJsonObject(): void
    {
        $expected = ['language' => 'nl', 'country' => 'be'];
        self::assertSame($expected, DimensionSelection::parseArgument($expected));
        self::assertSame($expected, DimensionSelection::parseArgument('{"language":"nl","country":"be"}'));
    }

    /**
     * @test
     * @dataProvider malformedArguments
     */
    public function malformedArgumentsAreRejected(mixed $raw): void
    {
        $this->expectException(InvalidDimensionsException::class);
        DimensionSelection::parseArgument($raw);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedArguments(): array
    {
        return ['not json' => ['zzz'], 'json scalar' => ['"nl"'], 'scalar' => [42]];
    }

    /**
     * @test
     */
    public function dimensionsLeftOutKeepTheSiteDefault(): void
    {
        $defaults = ['language' => 'en_US', 'country' => 'nl'];

        self::assertSame(['language' => 'nl', 'country' => 'nl'], $this->selection->complete(['language' => 'nl'], $defaults));
        self::assertSame(['language' => 'en_US', 'country' => 'be'], $this->selection->complete(['country' => 'be'], $defaults));
        self::assertSame(['language' => 'de', 'country' => 'be'], $this->selection->complete(['language' => 'de', 'country' => 'be'], $defaults));
    }

    /**
     * @test
     * @dataProvider invalidSelections
     * @param array<string, mixed> $requested
     */
    public function unknownDimensionsAndValuesAreRejectedWithTheAvailableOnes(array $requested, string $messagePart): void
    {
        $this->expectException(InvalidDimensionsException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');
        $this->selection->complete($requested, ['language' => 'en_US', 'country' => 'nl']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidSelections(): array
    {
        return [
            'unknown dimension' => [['region' => 'x'], 'Unknown dimension "region". Available: language, country'],
            'unknown value' => [['language' => 'fr'], 'Unknown value "fr" for dimension "language". Available: en_US, en_GB, de, nl'],
            'unknown value in the second dimension' => [['language' => 'nl', 'country' => 'xx'], 'Unknown value "xx" for dimension "country"'],
            'non-string value' => [['language' => ['nl']], 'Unknown value "array"'],
        ];
    }

    /**
     * @test
     */
    public function describingListsDimensionsDefaultsLabelsAndFallbackChains(): void
    {
        $described = $this->selection->describe(['language' => 'en_US', 'country' => 'nl']);

        self::assertSame(['language', 'country'], array_column($described, 'name'));
        self::assertSame(['en_US', 'nl'], array_column($described, 'default'));

        $language = array_column($described[0]['values'], null, 'value');
        self::assertSame([], $language['en_US']['fallback']);
        self::assertSame(['en_US'], $language['en_GB']['fallback']);
        self::assertSame('English (GB)', $language['en_GB']['label']);
        self::assertSame('nl', $language['nl']['label'], 'a value without label shows the value');

        self::assertSame(['nl'], array_column($described[1]['values'], 'fallback', 'value')['be']);
    }

    /**
     * @test
     */
    public function aFallbackChainFollowsSeveralGeneralizations(): void
    {
        $selection = new DimensionSelection([
            'language' => ['values' => [
                'en' => ['generalization' => null],
                'en_US' => ['generalization' => 'en'],
                'en_US_TX' => ['generalization' => 'en_US'],
            ]],
        ]);

        $values = array_column($selection->describe([])[0]['values'], 'fallback', 'value');
        self::assertSame(['en_US', 'en'], $values['en_US_TX']);
    }

    /**
     * @test
     */
    public function coordinatesAreComparedRegardlessOfKeyOrder(): void
    {
        self::assertTrue(DimensionSelection::sameCoordinates(['language' => 'nl', 'country' => 'be'], ['country' => 'be', 'language' => 'nl']));
        self::assertFalse(DimensionSelection::sameCoordinates(['language' => 'nl', 'country' => 'be'], ['language' => 'nl', 'country' => 'nl']));
        self::assertFalse(DimensionSelection::sameCoordinates(['language' => 'nl'], ['language' => 'nl', 'country' => 'nl']));
    }

    /**
     * @test
     */
    public function nodesWithChangesInOnlyTheRequestedDimensionsArePublishable(): void
    {
        $requested = ['language' => 'nl', 'country' => 'be'];
        $changes = [
            ['nodeAggregateId' => 'a', 'dimensions' => ['country' => 'be', 'language' => 'nl']],
            ['nodeAggregateId' => 'a', 'dimensions' => ['language' => 'nl', 'country' => 'be']],
            ['nodeAggregateId' => 'b', 'dimensions' => null],
        ];

        $result = DimensionSelection::partitionForPublishing($changes, $requested);

        self::assertSame(['a', 'b'], $result['publishable']);
        self::assertSame([], $result['skipped']);
    }

    /**
     * @test
     */
    public function nodesThatAlsoChangedInAnotherDimensionAreSkippedAndReported(): void
    {
        $requested = ['language' => 'nl', 'country' => 'be'];
        $changes = [
            ['nodeAggregateId' => 'a', 'dimensions' => ['language' => 'nl', 'country' => 'be']],
            ['nodeAggregateId' => 'a', 'dimensions' => ['language' => 'nl', 'country' => 'nl']],
            ['nodeAggregateId' => 'c', 'dimensions' => ['language' => 'de', 'country' => 'be']],
            ['nodeAggregateId' => 'd', 'dimensions' => ['language' => 'nl', 'country' => 'be']],
        ];

        $result = DimensionSelection::partitionForPublishing($changes, $requested);

        self::assertSame(['d'], $result['publishable']);
        self::assertCount(1, $result['skipped'], 'node c has no change in the requested dimensions, so it is neither published nor reported');
        self::assertSame('a', $result['skipped'][0]['nodeAggregateId']);
        self::assertSame([['language' => 'nl', 'country' => 'nl']], $result['skipped'][0]['otherDimensions']);
    }

    /**
     * @test
     */
    public function nothingIsPublishableWithoutChangesInTheRequestedDimensions(): void
    {
        self::assertSame(
            ['publishable' => [], 'skipped' => []],
            DimensionSelection::partitionForPublishing([], ['language' => 'nl'])
        );
    }
}
