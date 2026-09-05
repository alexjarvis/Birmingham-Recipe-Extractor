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
        $this->assertSame(['schema_version' => 1, 'recipes' => []], emptyRepository());
        $this->assertSame(['schema_version' => 1, 'events' => []], emptyChangelog());
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
}
