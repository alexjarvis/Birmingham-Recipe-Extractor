<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../utility/functions.php';
require_once __DIR__ . '/../utility/recipe_repository.php';

final class RecipeRepositoryTest extends TestCase
{
    private function scan(array $recipes, string $date = '2026-01-01', array $failed = []): array
    {
        return ['date' => $date, 'recipes' => $recipes, 'failed' => $failed];
    }

    private function recipe(string $title, array $components, ?string $image = null): array
    {
        return ['title' => $title, 'image' => $image, 'components' => $components];
    }

    public function testEmptyShapesCarrySchemaVersion(): void
    {
        $this->assertSame(['schema_version' => 2, 'recipes' => [], 'ingredients' => []], emptyRepository());
        $this->assertSame(['schema_version' => 2, 'events' => []], emptyChangelog());
    }

    public function testMergeAddsNewRecipeWithFirstSeenAndEvent(): void
    {
        $result = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'abacus' => $this->recipe('Abacus', ['Dilution Solution' => 2, 'Chimney Soot' => 3], 'Abacus.jpg'),
        ], '2024-11-09'));

        $this->assertTrue($result['changed']);
        $this->assertSame(['abacus'], $result['summary']['added']);
        $this->assertSame([
            'title' => 'Abacus',
            'handle' => 'abacus',
            'image' => 'Abacus.jpg',
            'components' => ['Chimney Soot' => 3, 'Dilution Solution' => 2],
            'first_seen' => '2024-11-09',
            'unlisted_on' => null,
        ], $result['repository']['recipes']['abacus']);
        $this->assertSame([
            ['date' => '2024-11-09', 'event' => 'added', 'handle' => 'abacus', 'title' => 'Abacus'],
        ], $result['changelog']['events']);
    }

    public function testMergeSkipsScannedRecipesWithNoComponents(): void
    {
        $result = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'empty' => $this->recipe('Empty', []),
        ]));

        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['repository']['recipes']);
    }

    public function testMergeRecordsFormulaChangeWithFromAndTo(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'abacus' => $this->recipe('Abacus', ['Chimney Soot' => 3, 'Dilution Solution' => 1]),
        ], '2025-01-01'));

        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'abacus' => $this->recipe('Abacus', ['Chimney Soot' => 3, 'Dilution Solution' => 2]),
        ], '2025-05-31'));

        $this->assertTrue($second['changed']);
        $this->assertSame(['abacus'], $second['summary']['changed']);
        $this->assertSame(['Chimney Soot' => 3, 'Dilution Solution' => 2], $second['repository']['recipes']['abacus']['components']);
        $this->assertSame('2025-01-01', $second['repository']['recipes']['abacus']['first_seen']);
        $this->assertSame([
            'date' => '2025-05-31',
            'event' => 'changed',
            'handle' => 'abacus',
            'title' => 'Abacus',
            'from' => ['Chimney Soot' => 3, 'Dilution Solution' => 1],
            'to' => ['Chimney Soot' => 3, 'Dilution Solution' => 2],
        ], $second['changelog']['events'][1]);
    }

    public function testMergeTreatsComponentOrderAsInsignificant(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1, 'Y' => 2]),
        ]));
        // Simulate a hand-edited, unsorted stored formula.
        $first['repository']['recipes']['a']['components'] = ['Y' => 2, 'X' => 1];

        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('A', ['Y' => 2, 'X' => 1]),
        ], '2026-01-02'));

        $this->assertCount(1, $second['changelog']['events']);
        $this->assertSame([], $second['summary']['changed']);
        $this->assertSame(['X' => 1, 'Y' => 2], $second['repository']['recipes']['a']['components']);
    }

    public function testMergeUpdatesTitleAndImageWithoutEvent(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('Old Title', ['X' => 1], 'old.jpg'),
        ]));
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('New Title', ['X' => 1], 'new.jpg'),
        ], '2026-01-02'));

        $this->assertTrue($second['changed']);
        $this->assertSame('New Title', $second['repository']['recipes']['a']['title']);
        $this->assertSame('new.jpg', $second['repository']['recipes']['a']['image']);
        $this->assertCount(1, $second['changelog']['events'], 'title/image updates are not events');
        $this->assertSame([], $second['summary']['changed']);
    }

    public function testMergeKeepsExistingImageWhenScanHasNone(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1], 'keep.jpg'),
        ]));
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('A', ['X' => 1], null),
        ], '2026-01-02'));

        $this->assertFalse($second['changed']);
        $this->assertSame('keep.jpg', $second['repository']['recipes']['a']['image']);
    }

    public function testMergeUnlistsRecipesAbsentFromScan(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1]),
            'b' => $this->recipe('B', ['X' => 1]),
        ], '2026-07-20'));
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('A', ['X' => 1]),
        ], '2026-07-21'));

        $this->assertTrue($second['changed']);
        $this->assertSame(['b'], $second['summary']['unlisted']);
        $this->assertNull($second['repository']['recipes']['a']['unlisted_on']);
        $this->assertSame('2026-07-21', $second['repository']['recipes']['b']['unlisted_on']);
        $this->assertCount(2, $second['changelog']['events'], 'unlisting is not an event');
    }

    public function testMergeDoesNotMoveExistingUnlistedDate(): void
    {
        $repo = emptyRepository();
        $repo['recipes']['b'] = [
            'title' => 'B', 'handle' => 'b', 'image' => null, 'components' => ['X' => 1],
            'first_seen' => '2025-01-01', 'unlisted_on' => '2026-07-21',
        ];
        $repo['ingredients'] = ['X' => ['image' => null, 'handle' => null]];

        $result = mergeScan($repo, emptyChangelog(), $this->scan([], '2026-08-05'));

        $this->assertFalse($result['changed']);
        $this->assertSame('2026-07-21', $result['repository']['recipes']['b']['unlisted_on']);
    }

    public function testMergeRelistsRecipeThatReappears(): void
    {
        $repo = emptyRepository();
        $repo['recipes']['b'] = [
            'title' => 'B', 'handle' => 'b', 'image' => null, 'components' => ['X' => 1],
            'first_seen' => '2025-01-01', 'unlisted_on' => '2026-07-21',
        ];

        $result = mergeScan($repo, emptyChangelog(), $this->scan([
            'b' => $this->recipe('B', ['X' => 1]),
        ], '2026-09-01'));

        $this->assertTrue($result['changed']);
        $this->assertNull($result['repository']['recipes']['b']['unlisted_on']);
        $this->assertSame('2025-01-01', $result['repository']['recipes']['b']['first_seen']);
        $this->assertSame([], $result['changelog']['events']);
    }

    public function testMergeLeavesFailedHandlesUntouched(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1]),
        ], '2026-01-01'));

        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([], '2026-01-02', ['a']));

        $this->assertFalse($second['changed']);
        $this->assertNull($second['repository']['recipes']['a']['unlisted_on']);
    }

    public function testMergeWithEmptyScanUnlistsEverything(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1]),
            'b' => $this->recipe('B', ['X' => 1]),
        ], '2026-07-20'));

        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([], '2026-07-21'));

        $this->assertSame(['a', 'b'], $second['summary']['unlisted']);
        $this->assertSame('2026-07-21', $second['repository']['recipes']['a']['unlisted_on']);
        $this->assertSame('2026-07-21', $second['repository']['recipes']['b']['unlisted_on']);
    }

    public function testMergeReportsNoChangeForIdenticalScan(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'a' => $this->recipe('A', ['X' => 1], 'a.jpg'),
        ]));
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('A', ['X' => 1], 'a.jpg'),
        ], '2026-01-02'));

        $this->assertFalse($second['changed']);
        $this->assertSame($first['repository'], $second['repository']);
        $this->assertSame($first['changelog'], $second['changelog']);
    }

    public function testMergeSortsRecipesByHandle(): void
    {
        $result = mergeScan(emptyRepository(), emptyChangelog(), $this->scan([
            'zebra' => $this->recipe('Zebra', ['X' => 1]),
            'apple' => $this->recipe('Apple', ['X' => 1]),
            'mango' => $this->recipe('Mango', ['X' => 1]),
        ]));

        $this->assertSame(['apple', 'mango', 'zebra'], array_keys($result['repository']['recipes']));
    }

    public function testSortComponentsOrdersByName(): void
    {
        $this->assertSame(['A' => 1, 'B' => 2, 'C' => 3], sortComponents(['C' => 3, 'A' => 1, 'B' => 2]));
    }

    public function testLoadJsonDocumentReturnsDefaultWhenMissing(): void
    {
        $this->assertSame(['x' => 1], loadJsonDocument(sys_get_temp_dir() . '/does-not-exist-' . uniqid() . '.json', ['x' => 1]));
    }

    public function testSaveAndLoadJsonDocumentRoundTrip(): void
    {
        $dir = sys_get_temp_dir() . '/repo-test-' . uniqid();
        $path = $dir . '/nested/recipes.json';
        $doc = ['schema_version' => 1, 'recipes' => ['a' => ['title' => 'Stoker\'s Ash / é', 'components' => ['X' => 1]]]];

        try {
            saveJsonDocument($path, $doc);
            $raw = file_get_contents($path);
            $this->assertIsString($raw);
            $this->assertStringEndsWith("\n", $raw);
            $this->assertStringContainsString('"Stoker\'s Ash / é"', $raw, 'slashes and unicode are not escaped');
            $this->assertSame($doc, loadJsonDocument($path, []));
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
            @rmdir($dir);
        }
    }

    public function testLoadJsonDocumentThrowsForInvalidJson(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, '{not json');
        try {
            $this->expectException(JsonException::class);
            loadJsonDocument($path, []);
        } finally {
            @unlink($path);
        }
    }

    public function testScanFromEnrichedProductsBuildsScanShape(): void
    {
        $products = [
            ['handle' => 'abacus', 'title' => 'Abacus', 'images' => [['src' => 'https://cdn/x/Abacus.jpg?v=1']], 'recipe_components' => ['Airline' => 3]],
            ['handle' => 'no-recipe', 'title' => 'Plain Ink', 'images' => [], 'recipe_components' => []],
            ['handle' => 'broken', 'title' => 'Broken', 'images' => [], 'recipe_components' => [], 'recipe_fetch_failed' => true],
            ['handle' => 'no-image', 'title' => 'No Image', 'images' => [], 'recipe_components' => ['Airline' => 1]],
        ];

        $scan = scanFromEnrichedProducts($products, '2026-09-05');

        $this->assertSame('2026-09-05', $scan['date']);
        $this->assertSame(['broken'], $scan['failed']);
        $this->assertSame([
            'abacus' => ['title' => 'Abacus', 'image' => 'Abacus.jpg', 'components' => ['Airline' => 3]],
            'no-image' => ['title' => 'No Image', 'image' => null, 'components' => ['Airline' => 1]],
        ], $scan['recipes']);
        $this->assertSame([
            'Abacus' => ['image' => 'Abacus.jpg', 'handle' => 'abacus'],
            'Broken' => ['image' => null, 'handle' => 'broken'],
            'No Image' => ['image' => null, 'handle' => 'no-image'],
            'Plain Ink' => ['image' => null, 'handle' => 'no-recipe'],
        ], $scan['ingredients'], 'every live product is a candidate ingredient, keyed by canonical title');
    }

    public function testScanFromEnrichedProductsCanonicalizesIngredientTitles(): void
    {
        $scan = scanFromEnrichedProducts([
            ['handle' => 'flint', 'title' => 'Flint', 'images' => [['src' => 'https://cdn/Flint.jpg']], 'recipe_components' => []],
            ['handle' => 'gunpowder', 'title' => 'Gunpowder', 'images' => [], 'recipe_components' => []],
        ], '2026-09-05');

        $this->assertSame(['Flint' => ['image' => 'Flint.jpg', 'handle' => 'flint']], $scan['ingredients'], 'a product carrying the real image wins over a renamed shell');
    }

    public function testMergeBuildsIngredientsFromRecipesUsingScanMetadata(): void
    {
        $scan = $this->scan(['a' => $this->recipe('A', ['Airline' => 1, 'Flint' => 2])]);
        $scan['ingredients'] = [
            'Airline' => ['image' => 'Airline.jpg', 'handle' => 'airline'],
            'Unrelated Pen' => ['image' => 'pen.jpg', 'handle' => 'pen'],
        ];

        $result = mergeScan(emptyRepository(), emptyChangelog(), $scan);

        $this->assertSame([
            'Airline' => ['image' => 'Airline.jpg', 'handle' => 'airline'],
            'Flint' => ['image' => null, 'handle' => null],
        ], $result['repository']['ingredients'], 'only ingredients used by a recipe are kept');
    }

    public function testMergeKeepsIngredientMetadataWhenScanLacksItAndUpdatesWhenPresent(): void
    {
        $repo = emptyRepository();
        $repo['recipes']['a'] = ['title' => 'A', 'handle' => 'a', 'image' => null, 'components' => ['Flint' => 2, 'Gone' => 1], 'first_seen' => '2025-01-01', 'unlisted_on' => null];
        $repo['ingredients'] = [
            'Flint' => ['image' => 'Gunpowder_Fountain_Pen_Ink.jpg', 'handle' => 'gunpowder'],
            'Gone' => ['image' => 'gone.jpg', 'handle' => 'gone'],
        ];

        $scan = $this->scan(['a' => $this->recipe('A', ['Flint' => 2])], '2026-09-05');
        $scan['ingredients'] = ['Flint' => ['image' => null, 'handle' => 'flint']];
        $result = mergeScan($repo, emptyChangelog(), $scan);

        $this->assertTrue($result['changed']);
        $this->assertSame(['Flint' => ['image' => 'Gunpowder_Fountain_Pen_Ink.jpg', 'handle' => 'flint']], $result['repository']['ingredients'], 'handle updated, image retained, unused ingredient dropped');
    }

    public function testMergeReportsNoChangeWhenIngredientsUnchanged(): void
    {
        $first = mergeScan(emptyRepository(), emptyChangelog(), $this->scan(['a' => $this->recipe('A', ['X' => 1])]));
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan(['a' => $this->recipe('A', ['X' => 1])], '2026-01-02'));

        $this->assertFalse($second['changed']);
        $this->assertSame(['X' => ['image' => null, 'handle' => null]], $second['repository']['ingredients']);
    }

    public function testScanFromEnrichedProductsSkipsProductsWithoutHandle(): void
    {
        $scan = scanFromEnrichedProducts([['title' => 'X', 'recipe_components' => ['A' => 1]]], '2026-09-05');

        $this->assertSame([], $scan['recipes']);
        $this->assertSame([], $scan['failed']);
    }
}
