# Recipe Repository Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the snapshot-of-the-storefront model with a committed repository of every recipe ever observed, rebuilt from the 121 archive snapshots and kept current by the daily scan.

**Architecture:** A pure `mergeScan()` function folds one dated scan result into `data/recipes.json` and `data/changelog.json`. The daily pipeline produces a scan result from the live storefront; a one-time rebuild script produces scan results by parsing archive HTML pages, then replays them through the same merge. Page generation reads the repository instead of the day's products. GitHub Actions commits repository changes to GitLab `main`, which mirrors to GitHub.

**Tech Stack:** PHP 8.3, procedural style, PHPUnit 11, PHPStan level 8, GitHub Actions, GitLab CI. No local PHP: run the toolchain via `docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine <cmd>`.

**Spec:** `docs/superpowers/specs/2026-09-05-recipe-repository-design.md`

## Global Constraints

- PHP `>=8.3 <9.0`; CI pins 8.3. PHPStan level 8 must stay clean.
- PHPUnit runs with `beStrictAboutOutputDuringTests`, `failOnRisky`, `failOnWarning`. Functions that print must take an injectable `$logger`.
- Procedural code: plain functions, callables injected for I/O. Two-space indentation, `TRUE`/`FALSE`/`NULL` uppercase in `utility/` and `operations/`, lowercase in tests (matches existing files).
- Data file field names exactly: `schema_version`, `recipes`, `title`, `handle`, `image`, `components`, `first_seen`, `unlisted_on`; changelog `events` with `date`, `event`, `handle`, `title`, `from`, `to`. Event names `added`, `changed`.
- Repository keyed by handle, sorted by handle; components sorted by name.
- Only `added` and `changed` events are logged. Listing flips are not.
- Bot commit message: `Update recipe repository (<YYYY-MM-DD>)`. Bot pushes to GitLab `main` only, never GitHub `main`.
- Test/lint commands (from repo root):
  - `docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine ./vendor/bin/phpunit`
  - `docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
- Commits end with the trailers:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01EksWeBATGgWYU5XqP6vpjx
  ```

---

## File structure

| Path | Responsibility |
|---|---|
| `config/config.php` | Path constants. Gains `DATA_DIR`, `RECIPES_FILE`, `CHANGELOG_FILE`, `SCAN_DATE`; products JSON moves to `var/products`; `ARCHIVE_FILE` removed. |
| `utility/recipe_repository.php` (new) | Repository/changelog shapes, JSON load/save, `mergeScan()`, `scanFromEnrichedProducts()`. No HTML. |
| `utility/archive_parser.php` (new) | `splitIngredientHeader()`, `parseArchiveSnapshot()`, `rebuildRepositoryFromArchive()`. Turns legacy HTML into scan results. |
| `utility/rebuild_from_archive.php` (new) | CLI wrapper only. |
| `utility/functions.php` | Fetching, page generation. Gains repository-to-page adapters, unlisted badge, Changes page; loses capture-rate stat and `extractTableContent*`. |
| `operations/fetch_products.php` | Unchanged except zero-products guard. |
| `operations/recipe_extractor.php` | Drops ratio guard; records `recipe_fetch_failed`. |
| `operations/merge_recipes.php` (new) | Step 3: merge today's scan into `data/`. |
| `operations/generate_table.php` | Step 4: render `output/index.html` from repository. |
| `operations/generate_changes.php` (new, replaces `generate_archive.php`) | Step 5: render `output/archive/index.html`. |
| `run.php` | Step order. |
| `output/template/styles.css` | Badge and Changes page styles. |
| `tests/RecipeRepositoryTest.php`, `tests/ArchiveParserTest.php` (new), `tests/FunctionsTest.php` | Tests. |
| `tests/fixtures/archive/*.html` (new) | Three mini snapshots for the rebuild end-to-end test. |
| `.github/workflows/deploy.yml` | Commit-to-GitLab step. |
| `README.md`, `composer.json`, `.gitignore` | Docs, autoload, ignores. |

---

### Task 1: Config, autoload, and scratch directory

**Files:**
- Modify: `config/config.php`
- Modify: `composer.json` (autoload files)
- Modify: `.gitignore`
- Delete: `output/products/.gitkeep`
- Create: `utility/recipe_repository.php`, `utility/archive_parser.php` (empty shells so autoload resolves)

**Interfaces:**
- Produces constants: `DATA_DIR`, `RECIPES_FILE`, `CHANGELOG_FILE`, `SCAN_DATE` (string `YYYY-MM-DD`, overridable via env `SCAN_DATE`), `PRODUCTS_DIR` now `<root>/var/products`.

- [ ] **Step 1: Rewrite `config/config.php`**

```php
<?php

$appRoot = realpath(__DIR__ . '/..');

// The scan date stamps merge events and the "Last checked" header. Override
// with SCAN_DATE=YYYY-MM-DD when replaying or testing.
$scanDate = getenv('SCAN_DATE');
define("SCAN_DATE", is_string($scanDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDate) ? $scanDate : date('Y-m-d'));

define("OUTPUT_DIR", $appRoot . '/output');
define("ARCHIVE_DIR", OUTPUT_DIR . '/archive');
define("TEMPLATE_DIR", OUTPUT_DIR . '/template');
define("IMAGE_DIR", OUTPUT_DIR . '/images');
define("INDEX_FILE", OUTPUT_DIR . '/index.html');
define("CHANGES_FILE", ARCHIVE_DIR . '/index.html');

// Scratch inputs for a single run. Not published, not committed.
define("PRODUCTS_DIR", $appRoot . '/var/products');
define("PRODUCTS_FILE", PRODUCTS_DIR . '/' . SCAN_DATE . '-products.json');
define("ENRICHED_PRODUCTS_FILE", PRODUCTS_DIR . '/' . SCAN_DATE . '-products_enriched.json');

// The committed recipe repository.
define("DATA_DIR", $appRoot . '/data');
define("RECIPES_FILE", DATA_DIR . '/recipes.json');
define("CHANGELOG_FILE", DATA_DIR . '/changelog.json');

const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;

const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
```

- [ ] **Step 2: Create the two utility shells**

`utility/recipe_repository.php`:
```php
<?php

// Recipe repository: shapes, persistence, and the merge that folds one scan
// into the repository. Pure data code; nothing here renders HTML.
```

`utility/archive_parser.php`:
```php
<?php

// Parses legacy snapshot pages (output/archive/*-recipes.html) back into scan
// results so the repository can be rebuilt from them.
```

- [ ] **Step 3: Register the files in `composer.json` autoload and regenerate**

```json
    "autoload": {
        "files": [
            "utility/functions.php",
            "utility/recipe_repository.php",
            "utility/archive_parser.php"
        ]
    },
```

Run: `docker run --rm -v "$PWD:/app" -w /app composer:2 composer dump-autoload --quiet`

- [ ] **Step 4: Update `.gitignore` and remove the products keep-file**

Replace the `.gitignore` products lines so it reads:
```
/var/
/output/index.html
/output/archive/*.html
/vendor/
/.phpunit.cache/
```
Run: `git rm -q output/products/.gitkeep`

- [ ] **Step 5: Run the suite and static analysis**

Expected: `OK (104 tests ...)` and PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add config/config.php composer.json composer.lock .gitignore utility/recipe_repository.php utility/archive_parser.php
git commit -m "Add data and scratch path config for recipe repository"
```

---

### Task 2: Repository shapes, persistence, and `mergeScan`

**Files:**
- Modify: `utility/recipe_repository.php`
- Create: `tests/RecipeRepositoryTest.php`

**Interfaces:**
- Produces:
  - `emptyRepository(): array` → `['schema_version'=>1,'recipes'=>[]]`
  - `emptyChangelog(): array` → `['schema_version'=>1,'events'=>[]]`
  - `loadJsonDocument(string $path, array $default): array`
  - `saveJsonDocument(string $path, array $data): void`
  - `sortComponents(array $components): array`
  - `mergeScan(array $repository, array $changelog, array $scan): array{repository: array, changelog: array, changed: bool, summary: array{added: string[], changed: string[], unlisted: string[]}}`
  - Scan shape: `['date'=>string, 'recipes'=>[handle=>['title'=>string,'image'=>?string,'components'=>array<string,int>]], 'failed'=>string[]]`

- [ ] **Step 1: Write the failing tests**

`tests/RecipeRepositoryTest.php`:
```php
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
        $second = mergeScan($first['repository'], $first['changelog'], $this->scan([
            'a' => $this->recipe('A', ['Y' => 2, 'X' => 1]),
        ], '2026-01-02'));

        $this->assertFalse($second['changed']);
        $this->assertCount(1, $second['changelog']['events']);
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
```

- [ ] **Step 2: Run to verify failure**

Run: `docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine ./vendor/bin/phpunit tests/RecipeRepositoryTest.php`
Expected: errors, `Call to undefined function emptyRepository()`.

- [ ] **Step 3: Implement `utility/recipe_repository.php`**

```php
<?php

// Recipe repository: shapes, persistence, and the merge that folds one scan
// into the repository. Pure data code; nothing here renders HTML.

const REPOSITORY_SCHEMA_VERSION = 1;

/**
 * @return array{schema_version:int, recipes: array<string, array<string, mixed>>}
 */
function emptyRepository(): array {
  return ['schema_version' => REPOSITORY_SCHEMA_VERSION, 'recipes' => []];
}

/**
 * @return array{schema_version:int, events: array<int, array<string, mixed>>}
 */
function emptyChangelog(): array {
  return ['schema_version' => REPOSITORY_SCHEMA_VERSION, 'events' => []];
}

/**
 * Load a JSON object from disk, or return $default when the file is absent.
 *
 * @param array<string, mixed> $default
 * @return array<string, mixed>
 * @throws \Exception
 */
function loadJsonDocument(string $path, array $default): array {
  if (!is_file($path)) {
    return $default;
  }
  $raw = file_get_contents($path);
  if ($raw === FALSE) {
    throw new Exception("Failed to read $path");
  }
  $data = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($data)) {
    throw new Exception("Invalid JSON document in $path");
  }
  return $data;
}

/**
 * Write a JSON object to disk, creating parent directories. Pretty-printed,
 * with slashes and unicode left alone so diffs stay readable.
 *
 * @param array<string, mixed> $data
 * @throws \Exception
 */
function saveJsonDocument(string $path, array $data): void {
  checkOutputDir(dirname($path));
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  if (file_put_contents($path, $json . "\n") === FALSE) {
    throw new Exception("Failed to write $path");
  }
}

/**
 * @param array<string, int> $components
 * @return array<string, int>
 */
function sortComponents(array $components): array {
  ksort($components, SORT_STRING);
  return $components;
}

/**
 * Fold one dated scan into the repository.
 *
 * Scan shape:
 *   ['date' => 'YYYY-MM-DD',
 *    'recipes' => [handle => ['title' => string, 'image' => ?string, 'components' => array<string,int>]],
 *    'failed' => string[]]   // handles whose page could not be fetched: neither updated nor unlisted
 *
 * Rules: a scanned recipe is added (with first_seen and an `added` event) or
 * updated (title/image replaced, `changed` event if the formula differs,
 * unlisted_on cleared). A repository recipe missing from the scan and not in
 * `failed` gets unlisted_on set if it is null. Listing flips are not events.
 *
 * @param array<string, mixed> $repository
 * @param array<string, mixed> $changelog
 * @param array<string, mixed> $scan
 * @return array{repository: array<string, mixed>, changelog: array<string, mixed>, changed: bool, summary: array{added: string[], changed: string[], unlisted: string[]}}
 */
function mergeScan(array $repository, array $changelog, array $scan): array {
  /** @var array<string, array<string, mixed>> $recipes */
  $recipes = $repository['recipes'] ?? [];
  /** @var array<int, array<string, mixed>> $events */
  $events = $changelog['events'] ?? [];
  $date = (string) $scan['date'];
  /** @var array<string, array<string, mixed>> $scanned */
  $scanned = $scan['recipes'] ?? [];
  $failed = array_fill_keys($scan['failed'] ?? [], TRUE);
  $summary = ['added' => [], 'changed' => [], 'unlisted' => []];
  $changed = FALSE;

  foreach ($scanned as $handle => $found) {
    $handle = (string) $handle;
    /** @var array<string, int> $components */
    $components = sortComponents($found['components'] ?? []);
    if ($components === []) {
      continue;
    }
    $title = (string) $found['title'];
    $image = $found['image'] ?? NULL;

    if (!isset($recipes[$handle])) {
      $recipes[$handle] = [
        'title' => $title,
        'handle' => $handle,
        'image' => $image,
        'components' => $components,
        'first_seen' => $date,
        'unlisted_on' => NULL,
      ];
      $events[] = ['date' => $date, 'event' => 'added', 'handle' => $handle, 'title' => $title];
      $summary['added'][] = $handle;
      $changed = TRUE;
      continue;
    }

    $existing = $recipes[$handle];
    $existing['components'] = sortComponents($existing['components'] ?? []);
    $updated = $existing;
    $updated['title'] = $title;
    if ($image !== NULL) {
      $updated['image'] = $image;
    }
    if ($existing['components'] !== $components) {
      $events[] = [
        'date' => $date,
        'event' => 'changed',
        'handle' => $handle,
        'title' => $title,
        'from' => $existing['components'],
        'to' => $components,
      ];
      $updated['components'] = $components;
      $summary['changed'][] = $handle;
    }
    $updated['unlisted_on'] = NULL;

    if ($updated !== $recipes[$handle]) {
      $recipes[$handle] = $updated;
      $changed = TRUE;
    }
  }

  foreach ($recipes as $handle => $recipe) {
    if (isset($scanned[$handle]) || isset($failed[$handle])) {
      continue;
    }
    if (($recipe['unlisted_on'] ?? NULL) === NULL) {
      $recipes[$handle]['unlisted_on'] = $date;
      $summary['unlisted'][] = (string) $handle;
      $changed = TRUE;
    }
  }

  ksort($recipes, SORT_STRING);

  return [
    'repository' => ['schema_version' => REPOSITORY_SCHEMA_VERSION, 'recipes' => $recipes],
    'changelog' => ['schema_version' => REPOSITORY_SCHEMA_VERSION, 'events' => $events],
    'changed' => $changed,
    'summary' => $summary,
  ];
}
```

- [ ] **Step 4: Run tests and static analysis**

Expected: RecipeRepositoryTest all pass; full suite green; PHPStan clean. Note `testMergeTreatsComponentOrderAsInsignificant` also guards that an unsorted stored formula does not produce a spurious `changed` event.

- [ ] **Step 5: Commit**

```bash
git add utility/recipe_repository.php tests/RecipeRepositoryTest.php
git commit -m "Add recipe repository shapes and mergeScan"
```

---

### Task 3: Archive snapshot parser

**Files:**
- Modify: `utility/archive_parser.php`
- Create: `tests/ArchiveParserTest.php`

**Interfaces:**
- Consumes: `correctTypos()` from `utility/functions.php`.
- Produces:
  - `splitIngredientHeader(string $header): array{0: string, 1: array<string,int>}` → primary name (typo-corrected) and fixed extra components from comma-joined artifacts.
  - `parseArchiveSnapshot(string $html, string $date): array` → scan shape from Task 2.

- [ ] **Step 1: Write the failing tests**

`tests/ArchiveParserTest.php`:
```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../utility/functions.php';
require_once __DIR__ . '/../utility/recipe_repository.php';
require_once __DIR__ . '/../utility/archive_parser.php';

final class ArchiveParserTest extends TestCase
{
    #[DataProvider('headerProvider')]
    public function testSplitIngredientHeader(string $header, string $primary, array $extras): void
    {
        $this->assertSame([$primary, $extras], splitIngredientHeader($header));
    }

    public static function headerProvider(): array
    {
        return [
            'plain' => ['Airline', 'Airline', []],
            'surrounding whitespace' => ["\n  Tesla Coil\n ", 'Tesla Coil', []],
            'typo corrected' => ['Diluent', 'Dilution Solution', []],
            'entity decoded' => ['Stoker&#039;s Ash', "Stoker's Ash", []],
            'comma artifact singular' => ['Gunpowder, 1 part Tesla Coil', 'Gunpowder', ['Tesla Coil' => 1]],
            'comma artifact plural' => ['Tesla Coil, 2 parts Teaberry Ice Cream', 'Tesla Coil', ['Teaberry Ice Cream' => 2]],
            'comma artifact with typo in extra' => ['Tesla Coil, 1 part Diluent', 'Tesla Coil', ['Dilution Solution' => 1]],
            'placeholder kept verbatim' => ['(Unreleased Element)', '(Unreleased Element)', []],
        ];
    }

    private function snapshot(string $thead, string $tbody): string
    {
        return '<head><meta charset="UTF-8" /><title>x</title></head><body><main><table>'
            . '<thead><tr><th>Product</th>' . $thead . '</tr></thead>'
            . '<tbody>' . $tbody . '</tbody></table></main></body>';
    }

    private function th(string $name, ?string $img = null): string
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        $html = '<th><a href="https://www.birminghampens.com/products/' . $slug . '" target="_blank">' . $name;
        if ($img !== null) {
            $html .= '<img src="../images/' . $img . '" alt="' . $name . '" class="ingredient-img" />';
        }
        return $html . '</a></th>';
    }

    public function testParses2024ShapeWithoutProductNameWrapper(): void
    {
        $html = $this->snapshot(
            $this->th('Airline', 'Airline.jpg') . $this->th('Chimney Soot'),
            '<tr><td><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a>'
            . '<img src="../images/Abacus.jpg" alt="Abacus" class="product-img" /></td>'
            . '<td>3</td><td></td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2024-11-09');

        $this->assertSame('2024-11-09', $scan['date']);
        $this->assertSame([], $scan['failed']);
        $this->assertSame([
            'abacus' => ['title' => 'Abacus', 'image' => 'Abacus.jpg', 'components' => ['Airline' => 3]],
        ], $scan['recipes']);
    }

    public function testParses2026ShapeWithProductCellAndQtyCells(): void
    {
        $html = $this->snapshot(
            $this->th('Airline') . $this->th('Chimney Soot'),
            '<tr><td><div class="product-cell">'
            . '<img class="product-img" src="../images/Abacus.jpg" alt="Abacus" />'
            . '<div class="product-name"><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a></div>'
            . '</div></td><td class="qty-cell"></td><td class="qty-cell">2</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2026-02-14');

        $this->assertSame(['Chimney Soot' => 2], $scan['recipes']['abacus']['components']);
        $this->assertSame('Abacus.jpg', $scan['recipes']['abacus']['image']);
    }

    public function testDecodesUrlEncodedHandleAndEntitiesInTitle(): void
    {
        $html = $this->snapshot(
            $this->th('Stoker&#039;s Ash'),
            '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/%28unreleased-element%29" target="_blank">(Unreleased Element)</a></div></td><td>1</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2025-06-06');

        $this->assertArrayHasKey('(unreleased-element)', $scan['recipes']);
        $this->assertSame(["Stoker's Ash" => 1], $scan['recipes']['(unreleased-element)']['components']);
    }

    public function testSplitsCommaArtifactHeaderIntoTwoComponents(): void
    {
        $html = $this->snapshot(
            $this->th('Gunpowder, 1 part Tesla Coil') . $this->th('Tesla Coil'),
            '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/a">A</a></div></td><td>4</td><td></td></tr>'
            . '<tr><td><div class="product-name"><a href="https://www.birminghampens.com/products/b">B</a></div></td><td>4</td><td>3</td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2026-05-10');

        $this->assertSame(['Gunpowder' => 4, 'Tesla Coil' => 1], $scan['recipes']['a']['components']);
        $this->assertSame(['Gunpowder' => 4, 'Tesla Coil' => 3], $scan['recipes']['b']['components'], 'larger value wins on collision');
    }

    public function testFoldsDiluentColumnIntoDilutionSolution(): void
    {
        $html = $this->snapshot(
            $this->th('Diluent') . $this->th('Dilution Solution'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>2</td><td></td></tr>'
        );

        $scan = parseArchiveSnapshot($html, '2025-01-08');

        $this->assertSame(['Dilution Solution' => 2], $scan['recipes']['a']['components']);
    }

    public function testEmptyTbodyYieldsNoRecipes(): void
    {
        $scan = parseArchiveSnapshot($this->snapshot($this->th('Airline'), ''), '2026-07-21');

        $this->assertSame([], $scan['recipes']);
    }

    public function testRowsWithoutQuantitiesAreSkipped(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td></td></tr>'
        );

        $this->assertSame([], parseArchiveSnapshot($html, '2025-01-01')['recipes']);
    }

    public function testMissingProductImageYieldsNull(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>1</td></tr>'
        );

        $this->assertNull(parseArchiveSnapshot($html, '2025-01-01')['recipes']['a']['image']);
    }

    public function testColumnCountMismatchThrows(): void
    {
        $html = $this->snapshot(
            $this->th('Airline') . $this->th('Chimney Soot'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>1</td></tr>'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2025-01-01');
        parseArchiveSnapshot($html, '2025-01-01');
    }

    public function testNonNumericQuantityThrows(): void
    {
        $html = $this->snapshot(
            $this->th('Airline'),
            '<tr><td><a href="https://www.birminghampens.com/products/a">A</a></td><td>lots</td></tr>'
        );

        $this->expectException(RuntimeException::class);
        parseArchiveSnapshot($html, '2025-01-01');
    }

    public function testMissingTableThrows(): void
    {
        $this->expectException(RuntimeException::class);
        parseArchiveSnapshot('<html><body><p>nothing</p></body></html>', '2025-01-01');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine ./vendor/bin/phpunit tests/ArchiveParserTest.php`
Expected: `Call to undefined function splitIngredientHeader()`.

- [ ] **Step 3: Implement the parser**

Replace `utility/archive_parser.php` with:
```php
<?php

// Parses legacy snapshot pages (output/archive/*-recipes.html) back into scan
// results so the repository can be rebuilt from them.

/**
 * Normalise an ingredient column header from a legacy snapshot.
 *
 * Snapshots written during the May 2026 parser bug (fixed in c075cbf) carry
 * headers such as "Gunpowder, 1 part Tesla Coil". A cell value q under that
 * header means q parts Gunpowder plus 1 part Tesla Coil, so the header splits
 * into a primary name (which takes the cell value) and fixed extras.
 *
 * @return array{0: string, 1: array<string, int>}
 */
function splitIngredientHeader(string $header): array {
  $header = trim(html_entity_decode($header, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  $extras = [];
  if (preg_match('/^(.+?),\s*(\d+)\s*parts?\s+(.+)$/i', $header, $m)) {
    $header = trim($m[1]);
    $extras[correctTypos(trim($m[3]))] = (int) $m[2];
  }
  return [correctTypos($header), $extras];
}

/**
 * Parse a legacy snapshot page into a scan result (see mergeScan()).
 *
 * Every snapshot from 2024-11 to 2026-08 shares this table shape: the first
 * <th> is the product column and each other <th> wraps an <a> whose text is
 * the ingredient name; each <tbody> row's first <td> holds an <a> to the
 * product URL (text = title) and optionally an <img>; the remaining cells are
 * integer quantities or empty. Wrapper divs and classes vary by year and are
 * ignored on purpose.
 *
 * @return array{date: string, recipes: array<string, array{title: string, image: ?string, components: array<string, int>}>, failed: array<int, string>}
 * @throws \RuntimeException on structural surprises
 */
function parseArchiveSnapshot(string $html, string $date): array {
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);
  $dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8'));
  libxml_clear_errors();
  $xpath = new DOMXPath($dom);

  $headerCells = $xpath->query('//table/thead/tr/th');
  if ($headerCells === FALSE || $headerCells->length === 0) {
    throw new RuntimeException("Snapshot $date: no table header found");
  }

  /** @var array<int, array{0: string, 1: array<string, int>}> $columns */
  $columns = [];
  foreach ($headerCells as $index => $th) {
    if ($index === 0) {
      continue;
    }
    $columns[] = splitIngredientHeader($th->textContent);
  }
  $expectedCells = count($columns) + 1;

  $rows = $xpath->query('//table/tbody/tr');
  $recipes = [];
  foreach ($rows === FALSE ? [] : $rows as $row) {
    $cells = $xpath->query('./td', $row);
    if ($cells === FALSE || $cells->length !== $expectedCells) {
      throw new RuntimeException(sprintf('Snapshot %s: row has %d cells, expected %d', $date, $cells === FALSE ? 0 : $cells->length, $expectedCells));
    }
    $productCell = $cells->item(0);
    if (!$productCell instanceof DOMNode) {
      throw new RuntimeException("Snapshot $date: row without product cell");
    }
    $anchors = $xpath->query('.//a[contains(@href, "/products/")]', $productCell);
    $anchor = $anchors === FALSE ? NULL : $anchors->item(0);
    if (!$anchor instanceof DOMElement) {
      throw new RuntimeException("Snapshot $date: product cell without product link");
    }
    $href = $anchor->getAttribute('href');
    $marker = '/products/';
    $pos = strrpos($href, $marker);
    $handle = rawurldecode(substr($href, ($pos === FALSE ? 0 : $pos) + strlen($marker)));
    $title = trim($anchor->textContent);

    $images = $xpath->query('.//img', $productCell);
    $img = $images === FALSE ? NULL : $images->item(0);
    $image = $img instanceof DOMElement ? basename($img->getAttribute('src')) : NULL;

    $components = [];
    for ($i = 1; $i < $cells->length; $i++) {
      $cell = $cells->item($i);
      $value = $cell instanceof DOMNode ? trim($cell->textContent) : '';
      if ($value === '') {
        continue;
      }
      if (!ctype_digit($value)) {
        throw new RuntimeException(sprintf('Snapshot %s: non-numeric quantity "%s" for %s', $date, $value, $handle));
      }
      [$primary, $extras] = $columns[$i - 1];
      $components[$primary] = max($components[$primary] ?? 0, (int) $value);
      foreach ($extras as $name => $qty) {
        $components[$name] = max($components[$name] ?? 0, $qty);
      }
    }
    if ($components === []) {
      continue;
    }
    $recipes[$handle] = ['title' => $title, 'image' => $image, 'components' => $components];
  }

  return ['date' => $date, 'recipes' => $recipes, 'failed' => []];
}
```

- [ ] **Step 4: Run tests and static analysis**

Expected: ArchiveParserTest passes; full suite green; PHPStan clean.

- [ ] **Step 5: Commit**

```bash
git add utility/archive_parser.php tests/ArchiveParserTest.php
git commit -m "Add legacy archive snapshot parser"
```

---

### Task 4: Rebuild function, CLI, and end-to-end fixture test

**Files:**
- Modify: `utility/archive_parser.php` (append `rebuildRepositoryFromArchive`)
- Create: `utility/rebuild_from_archive.php`
- Create: `tests/fixtures/archive/2025-01-01-recipes.html`, `2025-02-01-recipes.html`, `2025-03-01-recipes.html`
- Modify: `tests/ArchiveParserTest.php`

**Interfaces:**
- Consumes: `parseArchiveSnapshot()`, `mergeScan()`, `emptyRepository()`, `emptyChangelog()`, `saveJsonDocument()`.
- Produces: `rebuildRepositoryFromArchive(string $archiveDir, ?callable $logger = null): array{0: array, 1: array}` → `[repository, changelog]`.

- [ ] **Step 1: Create the three fixtures**

`tests/fixtures/archive/2025-01-01-recipes.html`:
```html
<head><meta charset="UTF-8" /><title>Birmingham Ink Recipes as of January 1, 2025</title></head>
<body><main><table>
<thead><tr><th>Product/Ingredients</th>
<th><a href="https://www.birminghampens.com/products/airline" target="_blank">Airline<img src="../images/Airline.jpg" alt="Airline" class="ingredient-img" /></a></th>
<th><a href="https://www.birminghampens.com/products/diluent" target="_blank">Diluent</a></th>
</tr></thead>
<tbody>
<tr><td><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a><img src="../images/Abacus.jpg" alt="Abacus" class="product-img" /></td><td>3</td><td>1</td></tr>
<tr><td><a href="https://www.birminghampens.com/products/kyanite" target="_blank">Kyanite</a></td><td>2</td><td></td></tr>
</tbody></table></main></body>
```

`tests/fixtures/archive/2025-02-01-recipes.html` (Abacus formula changes, Kyanite disappears, Gumball appears via a comma-artifact header):
```html
<head><meta charset="UTF-8" /><title>Birmingham Ink Recipes - February 1, 2025</title></head>
<body><main><table>
<thead><tr><th class="sortable">Product</th>
<th class="sortable"><a href="https://www.birminghampens.com/products/airline" target="_blank">Airline</a></th>
<th class="sortable"><a href="https://www.birminghampens.com/products/dilution-solution" target="_blank">Dilution Solution</a></th>
<th class="sortable"><a href="https://www.birminghampens.com/products/gunpowder%2C-1-part-tesla-coil" target="_blank">Gunpowder, 1 part Tesla Coil</a></th>
</tr></thead>
<tbody>
<tr><td><div class="product-cell"><img class="product-img" src="../images/Abacus.jpg" alt="Abacus" /><div class="product-name"><a href="https://www.birminghampens.com/products/abacus" target="_blank">Abacus</a></div></div></td><td class="qty-cell">3</td><td class="qty-cell">2</td><td class="qty-cell"></td></tr>
<tr><td><div class="product-cell"><div class="product-name"><a href="https://www.birminghampens.com/products/gumball" target="_blank">Gumball</a></div></div></td><td class="qty-cell"></td><td class="qty-cell"></td><td class="qty-cell">4</td></tr>
</tbody></table></main></body>
```

`tests/fixtures/archive/2025-03-01-recipes.html` (empty table):
```html
<head><meta charset="UTF-8" /><title>Birmingham Ink Recipes - March 1, 2025</title></head>
<body><main><table>
<thead><tr><th class="sortable">Product</th><th class="sortable"><a href="https://www.birminghampens.com/products/airline" target="_blank">Airline</a></th></tr></thead>
<tbody></tbody></table></main></body>
```

- [ ] **Step 2: Add the failing end-to-end test to `tests/ArchiveParserTest.php`**

```php
    public function testRebuildReplaysSnapshotsInDateOrder(): void
    {
        $log = [];
        [$repository, $changelog] = rebuildRepositoryFromArchive(
            __DIR__ . '/fixtures/archive',
            function (string $m) use (&$log): void { $log[] = $m; }
        );

        $this->assertSame(['abacus', 'gumball', 'kyanite'], array_keys($repository['recipes']));

        $abacus = $repository['recipes']['abacus'];
        $this->assertSame(['Airline' => 3, 'Dilution Solution' => 2], $abacus['components'], 'Diluent folded, latest formula wins');
        $this->assertSame('2025-01-01', $abacus['first_seen']);
        $this->assertSame('2025-03-01', $abacus['unlisted_on'], 'empty snapshot unlists everything');
        $this->assertSame('Abacus.jpg', $abacus['image']);

        $this->assertSame('2025-02-01', $repository['recipes']['kyanite']['unlisted_on']);
        $this->assertSame(['Gunpowder' => 4, 'Tesla Coil' => 1], $repository['recipes']['gumball']['components']);

        $this->assertSame(
            [
                ['2025-01-01', 'added', 'abacus'],
                ['2025-01-01', 'added', 'kyanite'],
                ['2025-02-01', 'changed', 'abacus'],
                ['2025-02-01', 'added', 'gumball'],
            ],
            array_map(fn($e) => [$e['date'], $e['event'], $e['handle']], $changelog['events'])
        );
        $this->assertSame(['Airline' => 3, 'Dilution Solution' => 1], $changelog['events'][2]['from']);

        $this->assertCount(3, $log);
        $this->assertStringContainsString('2025-01-01', $log[0]);
    }

    public function testRebuildThrowsWhenNoSnapshotsFound(): void
    {
        $this->expectException(RuntimeException::class);
        rebuildRepositoryFromArchive(sys_get_temp_dir() . '/no-such-dir-' . uniqid(), function (string $m): void {});
    }
```

- [ ] **Step 3: Run to verify failure**

Expected: `Call to undefined function rebuildRepositoryFromArchive()`.

- [ ] **Step 4: Append the rebuild function to `utility/archive_parser.php`**

```php

/**
 * Replay every *-recipes.html snapshot in $archiveDir, oldest first, through
 * mergeScan() starting from an empty repository.
 *
 * @param callable|null $logger fn(string $message): void  Defaults to stdout.
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}  [repository, changelog]
 * @throws \RuntimeException when the directory holds no snapshots
 */
function rebuildRepositoryFromArchive(string $archiveDir, ?callable $logger = NULL): array {
  $logger ??= fn(string $m) => print($m . PHP_EOL);
  $files = glob(rtrim($archiveDir, '/') . '/*-recipes.html') ?: [];
  sort($files, SORT_STRING);
  if ($files === []) {
    throw new RuntimeException("No *-recipes.html snapshots found in $archiveDir");
  }

  $repository = emptyRepository();
  $changelog = emptyChangelog();
  foreach ($files as $file) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})-recipes\.html$/', $file, $m)) {
      continue;
    }
    $html = file_get_contents($file);
    if ($html === FALSE) {
      throw new RuntimeException("Failed to read $file");
    }
    $scan = parseArchiveSnapshot($html, $m[1]);
    $result = mergeScan($repository, $changelog, $scan);
    $repository = $result['repository'];
    $changelog = $result['changelog'];
    $logger(sprintf(
      '%s: %d recipes in snapshot, +%d added, %d changed, %d unlisted',
      $m[1],
      count($scan['recipes']),
      count($result['summary']['added']),
      count($result['summary']['changed']),
      count($result['summary']['unlisted'])
    ));
  }
  return [$repository, $changelog];
}
```

- [ ] **Step 5: Create the CLI wrapper `utility/rebuild_from_archive.php`**

```php
<?php

// One-time rebuild of data/recipes.json and data/changelog.json from the
// legacy snapshot pages. Usage:
//   php utility/rebuild_from_archive.php <archive-dir> <data-dir>

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/recipe_repository.php';
require_once __DIR__ . '/archive_parser.php';

$archiveDir = $argv[1] ?? NULL;
$dataDir = $argv[2] ?? NULL;
if (!is_string($archiveDir) || !is_string($dataDir) || $archiveDir === '' || $dataDir === '') {
  fwrite(STDERR, "Usage: php utility/rebuild_from_archive.php <archive-dir> <data-dir>" . PHP_EOL);
  exit(1);
}

try {
  [$repository, $changelog] = rebuildRepositoryFromArchive($archiveDir);
  $dataDir = rtrim($dataDir, '/');
  saveJsonDocument($dataDir . '/recipes.json', $repository);
  saveJsonDocument($dataDir . '/changelog.json', $changelog);

  $listed = count(array_filter($repository['recipes'], fn(array $r) => $r['unlisted_on'] === NULL));
  printf(
    "Rebuilt %d recipes (%d currently listed), %d change-log events -> %s" . PHP_EOL,
    count($repository['recipes']),
    $listed,
    count($changelog['events']),
    $dataDir
  );
}
catch (Throwable $e) {
  fwrite(STDERR, "Rebuild failed: " . $e->getMessage() . PHP_EOL);
  exit(1);
}
```

- [ ] **Step 6: Run tests, static analysis, and the CLI against the fixtures**

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine sh -c \
  './vendor/bin/phpunit && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && \
   php utility/rebuild_from_archive.php tests/fixtures/archive /tmp/rebuild-check && cat /tmp/rebuild-check/recipes.json | head -30'
```
Expected: suite green, PHPStan clean, CLI prints `Rebuilt 3 recipes (0 currently listed), 4 change-log events`.

- [ ] **Step 7: Commit**

```bash
git add utility/archive_parser.php utility/rebuild_from_archive.php tests/ArchiveParserTest.php tests/fixtures
git commit -m "Add archive rebuild replay and CLI"
```

---

### Task 5: Harden product fetching

**Files:**
- Modify: `utility/functions.php` (`fetchAllProducts`)
- Modify: `operations/fetch_products.php`
- Modify: `tests/FunctionsTest.php` (`testFetchAllProductsBreaksOnFetchException`)

**Interfaces:**
- `fetchAllProducts()` now throws (propagates the `fetchPage` exception) instead of returning a partial list.

- [ ] **Step 1: Replace the test**

In `tests/FunctionsTest.php`, replace `testFetchAllProductsBreaksOnFetchException` with:
```php
    public function testFetchAllProductsThrowsWhenAPageFails(): void
    {
        $fetcher = fn(string $url): string|false => false;
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Max retries reached for page 1');
        fetchAllProducts($fetcher, $sleeper, $logger);
    }

    public function testFetchAllProductsThrowsWhenALaterPageFails(): void
    {
        $fetcher = function (string $url): string|false {
            preg_match('/[?&]page=(\d+)/', $url, $m);
            return (int) $m[1] === 1 ? json_encode(['products' => [['id' => 1]]]) : false;
        };
        $sleeper = function (int $s): void {};
        $logger = function (string $m): void {};

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('page 2');
        fetchAllProducts($fetcher, $sleeper, $logger);
    }
```

- [ ] **Step 2: Run to verify failure**

Expected: both fail with "Failed asserting that exception of type Exception is thrown".

- [ ] **Step 3: Make `fetchAllProducts` propagate**

In `utility/functions.php`, replace the body of the `while (TRUE)` loop in `fetchAllProducts` so it no longer catches:
```php
  while (TRUE) {
    // A page that fails after all retries throws. Swallowing it here used to
    // return a partial list, which the merge would read as "these recipes are
    // gone" — far worse than aborting the run and retrying tomorrow.
    $products = fetchPage($page, $fetcher, $sleeper, $logger);
    if (empty($products)) {
      $logger("No more products found on page $page. Stopping.");
      break;
    }

    $productCount = count($products);
    $logger("Retrieved $productCount products from page $page");
    $allProducts = array_merge($allProducts, $products);
    $page++;
  }
```
Update the docblock to add `@throws \Exception when a page fails after all retries`.

- [ ] **Step 4: Add the zero-products guard to `operations/fetch_products.php`**

After `$allProducts = fetchAllProducts();` insert:
```php
  if ($allProducts === []) {
    throw new RuntimeException('Storefront returned zero products; refusing to treat that as an empty catalogue.');
  }
```

- [ ] **Step 5: Run tests and static analysis**

Expected: green and clean.

- [ ] **Step 6: Commit**

```bash
git add utility/functions.php operations/fetch_products.php tests/FunctionsTest.php
git commit -m "Abort the run on partial or empty product fetches"
```

---

### Task 6: Extractor emits failures; merge step

**Files:**
- Modify: `operations/recipe_extractor.php`
- Modify: `utility/recipe_repository.php` (append `scanFromEnrichedProducts`)
- Create: `operations/merge_recipes.php`
- Modify: `tests/RecipeRepositoryTest.php`

**Interfaces:**
- Enriched product gains `recipe_fetch_failed: bool`.
- Produces: `scanFromEnrichedProducts(array $products, string $date): array` → scan shape.

- [ ] **Step 1: Add failing tests to `tests/RecipeRepositoryTest.php`**

```php
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
    }

    public function testScanFromEnrichedProductsSkipsProductsWithoutHandle(): void
    {
        $scan = scanFromEnrichedProducts([['title' => 'X', 'recipe_components' => ['A' => 1]]], '2026-09-05');

        $this->assertSame([], $scan['recipes']);
        $this->assertSame([], $scan['failed']);
    }
```

- [ ] **Step 2: Run to verify failure**

Expected: `Call to undefined function scanFromEnrichedProducts()`.

- [ ] **Step 3: Append to `utility/recipe_repository.php`**

```php

/**
 * Build a scan result from the extractor's enriched product list.
 *
 * @param array<int, array<string, mixed>> $products
 * @return array{date: string, recipes: array<string, array{title: string, image: ?string, components: array<string, int>}>, failed: array<int, string>}
 */
function scanFromEnrichedProducts(array $products, string $date): array {
  $recipes = [];
  $failed = [];
  foreach ($products as $product) {
    $handle = (string) ($product['handle'] ?? '');
    if ($handle === '') {
      continue;
    }
    if (!empty($product['recipe_fetch_failed'])) {
      $failed[] = $handle;
      continue;
    }
    $components = $product['recipe_components'] ?? [];
    if (!is_array($components) || $components === []) {
      continue;
    }
    $src = $product['images'][0]['src'] ?? NULL;
    $image = is_string($src) && $src !== '' ? cleanImageName($src) : NULL;
    /** @var array<string, int> $components */
    $recipes[$handle] = [
      'title' => (string) ($product['title'] ?? $handle),
      'image' => $image,
      'components' => $components,
    ];
  }
  return ['date' => $date, 'recipes' => $recipes, 'failed' => $failed];
}
```

- [ ] **Step 4: Rework `operations/recipe_extractor.php`**

Replace the file with:
```php
<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

const RECIPE_FETCH_INTER_REQUEST_DELAY_MICROSECONDS = 250000; // 250ms politeness delay

try {
  checkInputFile(PRODUCTS_FILE);
  $products = loadProducts(PRODUCTS_FILE);

  echo "Processing " . count($products) . " products...\n";
  $enrichedProducts = [];
  $recipesFound = 0;
  $taggedRecipeCount = 0;
  $fetchFailures = [];

  foreach ($products as $product) {
    $price = $product['variants'][0]['price'];
    $compareAtPrice = $product['variants'][0]['compare_at_price'];
    $available = $product['variants'][0]['available'];

    $enrichedProduct = [
      'id' => $product['id'],
      'title' => $product['title'],
      'handle' => $product['handle'],
      'price' => $price,
      'on_sale' => $compareAtPrice > $price,
      'sold_out' => !$available,
      'tags' => $product['tags'],
      'images' => $product['images'],
      'vendor' => $product['vendor'],
      'product_type' => $product['product_type'],
      'variants' => $product['variants'],
      'body_html' => $product['body_html'],
      'recipe_fetch_failed' => FALSE,
    ];

    $recipeHtml = '';

    if (strpos($product['body_html'], 'Ink Recipe') !== FALSE) {
      // Special case: recipe lives directly in the product description.
      $recipeHtml = $product['body_html'];
    }
    elseif (in_array('recipe', $product['tags'])) {
      $taggedRecipeCount++;
      $pageHtml = fetchProductPage(PRODUCT_URL . $product['handle']);

      if ($pageHtml === NULL) {
        // The merge treats a failed fetch as "unknown", not "gone".
        $enrichedProduct['recipe_fetch_failed'] = TRUE;
        $fetchFailures[] = $product['title'];
        echo "  ✗ " . $product['title'] . " (fetch failed after " . RECIPE_FETCH_MAX_ATTEMPTS . " attempts)\n";
      }
      else {
        $recipeHtml = extractRecipeHtmlFromPage($pageHtml);
      }

      usleep(RECIPE_FETCH_INTER_REQUEST_DELAY_MICROSECONDS);
    }

    $enrichedProduct['recipe'] = trim($recipeHtml);
    $enrichedProduct['recipe_components'] = parseRecipeComponents($recipeHtml);

    if (!empty($enrichedProduct['recipe_components'])) {
      $recipesFound++;
      echo "  ✓ " . $product['title'] . " (recipe with " . count($enrichedProduct['recipe_components']) . " ingredients)\n";
    }
    $enrichedProducts[] = $enrichedProduct;
  }

  echo "\nSummary:\n";
  echo "  Total products processed: " . count($products) . "\n";
  echo "  Products with recipes: $recipesFound\n";
  echo "  Recipe-tagged products: $taggedRecipeCount\n";
  if (!empty($fetchFailures)) {
    echo "  Fetch failures (left unchanged in the repository): " . implode(', ', $fetchFailures) . "\n";
  }

  file_put_contents(ENRICHED_PRODUCTS_FILE, json_encode($enrichedProducts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
  echo "  Enriched data written to " . ENRICHED_PRODUCTS_FILE . PHP_EOL;
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
```

- [ ] **Step 5: Create `operations/merge_recipes.php`**

```php
<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');
require_once(__DIR__ . '/../utility/recipe_repository.php');

try {
  checkInputFile(ENRICHED_PRODUCTS_FILE);
  $scan = scanFromEnrichedProducts(loadProducts(ENRICHED_PRODUCTS_FILE), SCAN_DATE);

  $repository = loadJsonDocument(RECIPES_FILE, emptyRepository());
  $changelog = loadJsonDocument(CHANGELOG_FILE, emptyChangelog());

  $result = mergeScan($repository, $changelog, $scan);
  $summary = $result['summary'];

  echo "Scan " . SCAN_DATE . ": " . count($scan['recipes']) . " recipes on site, " . count($scan['failed']) . " fetch failures\n";
  foreach (['added', 'changed', 'unlisted'] as $kind) {
    if ($summary[$kind] !== []) {
      echo "  " . ucfirst($kind) . " (" . count($summary[$kind]) . "): " . implode(', ', $summary[$kind]) . "\n";
    }
  }

  if ($result['changed']) {
    saveJsonDocument(RECIPES_FILE, $result['repository']);
    saveJsonDocument(CHANGELOG_FILE, $result['changelog']);
    echo "✓ Repository updated: " . RECIPES_FILE . " and " . CHANGELOG_FILE . PHP_EOL;
  }
  else {
    echo "✗ No repository changes.\n";
  }
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
```

- [ ] **Step 6: Run tests and static analysis**

Expected: green and clean.

- [ ] **Step 7: Commit**

```bash
git add operations/recipe_extractor.php operations/merge_recipes.php utility/recipe_repository.php tests/RecipeRepositoryTest.php
git commit -m "Record fetch failures and add the repository merge step"
```

---

### Task 7: Page renders from the repository

**Files:**
- Modify: `utility/functions.php`
- Modify: `operations/generate_table.php`
- Modify: `output/template/styles.css`
- Modify: `tests/FunctionsTest.php`

**Interfaces:**
- Produces:
  - `repositoryRecipesForPage(array $repository): array<int, array{handle: string, title: string, recipe_components: array<string,int>, unlisted_on: ?string, image: ?string}>` sorted by title.
  - `ingredientTotals(array $recipes): array<string,int>`
  - `repositoryImages(array $recipes, string $imageDir): array<string,string>` title → absolute path.
  - `countListed(array $recipes): int`
  - `formatUnlistedBadge(?string $unlistedOn): string`
  - `generatePageHeader(string $checkedDate, int $recipeCount, int $ingredientCount, int $listedCount): string`
  - `generateHTML(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages, ?string $checkedDate = NULL): string`
- Removes: `extractTableContent()`, `extractTableContentFromString()` and their six tests.

- [ ] **Step 1: Update and add tests in `tests/FunctionsTest.php`**

Delete these tests: `testGenerateHTMLComputesCaptureRateFromTotalTagged`, `testGenerateHTMLDefaultsTo100PercentWhenTotalTaggedOmitted`, `testGenerateHTMLAvoidsDivisionByZero`, `testExtractTableContentReturnsTableHtml`, `testExtractTableContentReturnsEmptyWhenNoTable`, `testExtractTableContentReturnsFirstTableWhenMultiple`, `testExtractTableContentFromStringReturnsTableHtml`, `testExtractTableContentFromStringReturnsEmptyForEmptyInput`, `testExtractTableContentFromStringReturnsEmptyWhenNoTable`.

Replace the last two assertions of `testGenerateHTMLProducesCompleteDocumentWithStats` with:
```php
        $this->assertMatchesRegularExpression('/stat-value">2<.*?stat-label">Recipes/s', $html);
        $this->assertMatchesRegularExpression('/stat-value">2<.*?stat-label">Ingredients/s', $html);
        $this->assertMatchesRegularExpression('/stat-value">2<.*?stat-label">On site now/s', $html);
        $this->assertStringNotContainsString('Captured', $html);
```

Replace `testGeneratePageHeaderEmbedsStats` with:
```php
    public function testGeneratePageHeaderEmbedsStats(): void
    {
        $header = generatePageHeader('March 14, 2026', 156, 25, 2);

        $this->assertStringContainsString('Last checked March 14, 2026', $header);
        $this->assertStringContainsString('<div class="stat-value">156</div><div class="stat-label">Recipes</div>', $header);
        $this->assertStringContainsString('<div class="stat-value">25</div><div class="stat-label">Ingredients</div>', $header);
        $this->assertStringContainsString('<div class="stat-value">2</div><div class="stat-label">On site now</div>', $header);
    }
```

Add these new tests:
```php
    public function testGenerateHTMLCountsOnlyListedRecipesForOnSiteStat(): void
    {
        $recipes = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 1], 'unlisted_on' => null],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['X' => 1], 'unlisted_on' => '2026-07-21'],
            ['handle' => 'c', 'title' => 'C', 'recipe_components' => ['X' => 1], 'unlisted_on' => '2026-07-21'],
        ];

        $html = generateHTML($recipes, ['X'], ['X' => 3], [], 'September 5, 2026');

        $this->assertMatchesRegularExpression('/stat-value">3<.*?stat-label">Recipes/s', $html);
        $this->assertMatchesRegularExpression('/stat-value">1<.*?stat-label">On site now/s', $html);
        $this->assertStringContainsString('Last checked September 5, 2026', $html);
    }

    public function testFormatUnlistedBadge(): void
    {
        $this->assertSame('', formatUnlistedBadge(null));
        $this->assertSame(
            '<span class="unlisted-badge" title="Last seen on birminghampens.com before 2026-07-21">Not listed since Jul 21, 2026</span>',
            formatUnlistedBadge('2026-07-21')
        );
    }

    public function testGenerateRecipeCardMarksUnlistedRecipe(): void
    {
        $product = ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['X' => 1], 'unlisted_on' => '2026-07-21'];

        $html = generateRecipeCard($product, []);

        $this->assertStringContainsString('<div class="recipe-card unlisted">', $html);
        $this->assertStringContainsString('Not listed since Jul 21, 2026', $html);
    }

    public function testGenerateRecipeCardOmitsBadgeForListedRecipe(): void
    {
        $product = ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 1], 'unlisted_on' => null];

        $html = generateRecipeCard($product, []);

        $this->assertStringContainsString('<div class="recipe-card">', $html);
        $this->assertStringNotContainsString('unlisted-badge', $html);
    }

    public function testGenerateTableRowMarksUnlistedRecipe(): void
    {
        $product = ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['X' => 1], 'unlisted_on' => '2026-07-21'];

        $html = generateTableRow($product, ['X'], []);

        $this->assertStringStartsWith('<tr class="unlisted">', $html);
        $this->assertStringContainsString('unlisted-badge', $html);
    }

    public function testGenerateTableRowIsPlainForListedRecipe(): void
    {
        $product = ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 1]];

        $html = generateTableRow($product, ['X'], []);

        $this->assertStringStartsWith('<tr><td>', $html);
        $this->assertStringNotContainsString('unlisted-badge', $html);
    }

    public function testRepositoryRecipesForPageAdaptsAndSortsByTitle(): void
    {
        $repository = ['schema_version' => 1, 'recipes' => [
            'zeta' => ['title' => 'Zeta', 'handle' => 'zeta', 'image' => 'Zeta.jpg', 'components' => ['X' => 1], 'first_seen' => '2025-01-01', 'unlisted_on' => null],
            'alpha' => ['title' => 'Alpha', 'handle' => 'alpha', 'image' => null, 'components' => ['X' => 2, 'Y' => 3], 'first_seen' => '2025-01-01', 'unlisted_on' => '2026-07-21'],
        ]];

        $recipes = repositoryRecipesForPage($repository);

        $this->assertSame(['Alpha', 'Zeta'], array_column($recipes, 'title'));
        $this->assertSame([
            'handle' => 'alpha', 'title' => 'Alpha', 'recipe_components' => ['X' => 2, 'Y' => 3],
            'unlisted_on' => '2026-07-21', 'image' => null,
        ], $recipes[0]);
    }

    public function testIngredientTotalsAndCountListed(): void
    {
        $recipes = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => ['X' => 2, 'Y' => 3], 'unlisted_on' => null, 'image' => null],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => ['X' => 1], 'unlisted_on' => '2026-07-21', 'image' => null],
        ];

        $this->assertSame(['X' => 3, 'Y' => 3], ingredientTotals($recipes));
        $this->assertSame(1, countListed($recipes));
    }

    public function testRepositoryImagesMapsTitlesToImageDir(): void
    {
        $recipes = [
            ['handle' => 'a', 'title' => 'A', 'recipe_components' => [], 'unlisted_on' => null, 'image' => 'A.jpg'],
            ['handle' => 'b', 'title' => 'B', 'recipe_components' => [], 'unlisted_on' => null, 'image' => null],
        ];

        $this->assertSame(['A' => '/img/A.jpg'], repositoryImages($recipes, '/img'));
    }
```

- [ ] **Step 2: Run to verify failure**

Expected: undefined function errors for `formatUnlistedBadge`, `repositoryRecipesForPage`, `ingredientTotals`, `countListed`, `repositoryImages`; header/stat assertion failures.

- [ ] **Step 3: Modify `utility/functions.php`**

Delete `extractTableContent()` and `extractTableContentFromString()`.

Replace `generatePageHeader`:
```php
/**
 * Generate the page header with stats bar, theme toggle, and changes link.
 */
function generatePageHeader(string $checkedDate, int $recipeCount, int $ingredientCount, int $listedCount): string {
  return '<header>'
    . '<div class="header-content">'
    . '<div class="header-top">'
    . '<div><h1>Birmingham Ink Recipes</h1><div class="header-date">Last checked ' . $checkedDate . '</div></div>'
    . '<div class="header-actions">'
    . '<div class="theme-toggle" id="themeToggle"></div>'
    . '<a href="index.html" class="btn btn-icon" title="Changes">🗂️</a>'
    . '</div></div>'
    . '<div class="stats-bar">'
    . '<div class="stat"><div><div class="stat-value">' . $recipeCount . '</div><div class="stat-label">Recipes</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $ingredientCount . '</div><div class="stat-label">Ingredients</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $listedCount . '</div><div class="stat-label">On site now</div></div></div>'
    . '</div></div></header>';
}
```

Replace `generateHTML`:
```php
/**
 * Generate HTML for the complete recipe page.
 *
 * @param array<int, array<string, mixed>> $enrichedProducts  Page recipes (see repositoryRecipesForPage()).
 * @param array<int, string> $allIngredients
 * @param array<string, int> $ingredientTotals
 * @param array<string, string> $productImages
 * @param string|null $checkedDate  Human date of the scan; defaults to today.
 */
function generateHTML(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages, ?string $checkedDate = NULL): string {
  $checkedDate ??= date('F j, Y');
  $recipeCount = count($enrichedProducts);
  $ingredientCount = count($allIngredients);
  $listedCount = countListed($enrichedProducts);

  return generateDocumentHead($checkedDate)
    . generatePageHeader($checkedDate, $recipeCount, $ingredientCount, $listedCount)
    . '<main>'
    . generateSearchControls()
    . generateFilterPills($allIngredients)
    . generateCardView($enrichedProducts, $productImages)
    . generateTableView($enrichedProducts, $allIngredients, $ingredientTotals, $productImages)
    . '</main>'
    . '<script src="../template/script.js"></script>'
    . '</body></html>';
}
```

In `generateRecipeCard`, change the opening div and insert the badge after the title:
```php
  $unlistedOn = $product['unlisted_on'] ?? NULL;
  $html = '<div class="recipe-card' . ($unlistedOn !== NULL ? ' unlisted' : '') . '">';
```
and after the `<h3 class="card-title">…</h3>` line:
```php
  $html .= formatUnlistedBadge($unlistedOn);
```

In `generateTableRow`, change the opening and the product cell close:
```php
  $unlistedOn = $product['unlisted_on'] ?? NULL;
  $html = ($unlistedOn !== NULL ? '<tr class="unlisted">' : '<tr>') . '<td><div class="product-cell">';
```
and replace the `$html .= '</div></td>';` after the product name with:
```php
  $html .= formatUnlistedBadge($unlistedOn);
  $html .= '</div></td>';
```

Add these functions (after `getQuantityClass`):
```php
/**
 * Badge shown on recipes the storefront no longer lists. Empty string when listed.
 */
function formatUnlistedBadge(?string $unlistedOn): string {
  if ($unlistedOn === NULL) {
    return '';
  }
  $ts = strtotime($unlistedOn);
  $human = $ts === FALSE ? $unlistedOn : date('M j, Y', $ts);
  return '<span class="unlisted-badge" title="Last seen on birminghampens.com before ' . htmlspecialchars($unlistedOn) . '">'
    . 'Not listed since ' . htmlspecialchars($human) . '</span>';
}

/**
 * Adapt the repository to the product shape the page generators consume.
 *
 * @param array<string, mixed> $repository
 * @return array<int, array{handle: string, title: string, recipe_components: array<string, int>, unlisted_on: ?string, image: ?string}>
 */
function repositoryRecipesForPage(array $repository): array {
  $recipes = [];
  /** @var array<string, array<string, mixed>> $entries */
  $entries = $repository['recipes'] ?? [];
  foreach ($entries as $handle => $recipe) {
    /** @var array<string, int> $components */
    $components = $recipe['components'] ?? [];
    $recipes[] = [
      'handle' => (string) ($recipe['handle'] ?? $handle),
      'title' => (string) ($recipe['title'] ?? $handle),
      'recipe_components' => $components,
      'unlisted_on' => isset($recipe['unlisted_on']) ? (string) $recipe['unlisted_on'] : NULL,
      'image' => isset($recipe['image']) ? (string) $recipe['image'] : NULL,
    ];
  }
  usort($recipes, fn(array $a, array $b) => strcmp($a['title'], $b['title']));
  return $recipes;
}

/**
 * @param array<int, array<string, mixed>> $recipes
 * @return array<string, int>
 */
function ingredientTotals(array $recipes): array {
  $totals = [];
  foreach ($recipes as $recipe) {
    foreach ($recipe['recipe_components'] ?? [] as $ingredient => $quantity) {
      $totals[$ingredient] = ($totals[$ingredient] ?? 0) + (int) $quantity;
    }
  }
  return $totals;
}

/**
 * @param array<int, array<string, mixed>> $recipes
 */
function countListed(array $recipes): int {
  return count(array_filter($recipes, fn(array $r) => ($r['unlisted_on'] ?? NULL) === NULL));
}

/**
 * Title => absolute image path for repository recipes that carry an image name.
 *
 * @param array<int, array<string, mixed>> $recipes
 * @return array<string, string>
 */
function repositoryImages(array $recipes, string $imageDir): array {
  $images = [];
  foreach ($recipes as $recipe) {
    $image = $recipe['image'] ?? NULL;
    if (is_string($image) && $image !== '') {
      $images[(string) $recipe['title']] = rtrim($imageDir, '/') . '/' . $image;
    }
  }
  return $images;
}
```

- [ ] **Step 4: Rewrite `operations/generate_table.php`**

```php
<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');
require_once(__DIR__ . '/../utility/recipe_repository.php');

try {
  checkInputFile(ENRICHED_PRODUCTS_FILE);
  checkOutputDir(OUTPUT_DIR);
  checkOutputDir(IMAGE_DIR);

  // The page renders the whole repository, not just what the site lists today.
  $repository = loadJsonDocument(RECIPES_FILE, emptyRepository());
  $recipes = repositoryRecipesForPage($repository);

  // Today's product list still drives image downloads: base inks used as
  // ingredients (Airline, Chimney Soot, ...) are live products with images.
  $liveProducts = loadProducts(ENRICHED_PRODUCTS_FILE);
  [, , $liveImages] = processProducts($liveProducts);
  $productImages = array_merge(repositoryImages($recipes, IMAGE_DIR), $liveImages);

  $ingredientTotals = ingredientTotals($recipes);
  $allIngredients = array_keys($ingredientTotals);
  sort($allIngredients);

  echo "Recipes in repository: " . count($recipes) . "\n";
  echo "Currently listed: " . countListed($recipes) . "\n";
  echo "Unique ingredients: " . count($allIngredients) . "\n";

  $checkedTs = strtotime(SCAN_DATE);
  $checkedDate = $checkedTs === FALSE ? SCAN_DATE : date('F j, Y', $checkedTs);
  $html = generateHTML($recipes, $allIngredients, $ingredientTotals, $productImages, $checkedDate);

  file_put_contents(INDEX_FILE, prettifyHTML($html));
  updatePathsInIndex(INDEX_FILE);
  echo "✓ Wrote " . INDEX_FILE . PHP_EOL;
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
  throw $e;
}
```

- [ ] **Step 5: Add badge styles to `output/template/styles.css`**

Insert before `/* Utilities */`:
```css
/* Unlisted recipes: still in the repository, no longer on the storefront */
.unlisted-badge {
  display: inline-block;
  margin-top: 0.35rem;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  white-space: nowrap;
  background: var(--bg-tertiary);
  color: var(--text-secondary);
}

.recipe-card.unlisted .card-title a,
tr.unlisted .product-name a {
  color: var(--text-secondary);
}

.recipe-card.unlisted .card-image {
  opacity: 0.65;
}

.product-cell .unlisted-badge {
  margin-top: 0;
  margin-left: auto;
}
```

- [ ] **Step 6: Run tests and static analysis**

Expected: green and clean. If PHPStan complains that `updatePathsInIndex` rewrites a `title="Archive"` anchor that no longer exists, that is Task 8's concern; leave it.

- [ ] **Step 7: Commit**

```bash
git add utility/functions.php operations/generate_table.php output/template/styles.css tests/FunctionsTest.php
git commit -m "Render the recipe page from the repository with unlisted badges"
```

---

### Task 8: Changes page, run order, and archive link

**Files:**
- Modify: `utility/functions.php` (`generateChangesPage`, `describeFormulaChange`, `updatePathsInIndex`)
- Create: `operations/generate_changes.php`
- Delete: `operations/generate_archive.php`
- Modify: `run.php`
- Modify: `output/template/styles.css`
- Modify: `tests/FunctionsTest.php`

**Interfaces:**
- Produces:
  - `describeFormulaChange(array $from, array $to): array<int, string>` lines like `Dilution Solution: 1 → 2`, `Tesla Coil: added (1)`, `Airline: removed`.
  - `generateChangesPage(array $events, array $snapshotFiles, string $generationDate): string`

- [ ] **Step 1: Update and add tests in `tests/FunctionsTest.php`**

Update `testUpdatePathsInIndexRewritesArchiveLink` so the fixture anchor uses `title="Changes"` and the assertion expects `<a href="archive/" class="btn btn-icon" title="Changes">`.

Add:
```php
    public function testDescribeFormulaChangeListsOnlyDifferences(): void
    {
        $lines = describeFormulaChange(
            ['Airline' => 3, 'Dilution Solution' => 1, 'Gone' => 2],
            ['Airline' => 3, 'Dilution Solution' => 2, 'Tesla Coil' => 1]
        );

        $this->assertSame([
            'Dilution Solution: 1 → 2',
            'Gone: removed',
            'Tesla Coil: added (1)',
        ], $lines);
    }

    public function testGenerateChangesPageGroupsEventsNewestFirst(): void
    {
        $events = [
            ['date' => '2024-11-09', 'event' => 'added', 'handle' => 'abacus', 'title' => 'Abacus'],
            ['date' => '2025-05-31', 'event' => 'changed', 'handle' => 'abacus', 'title' => 'Abacus',
             'from' => ['Chimney Soot' => 3, 'Dilution Solution' => 1], 'to' => ['Chimney Soot' => 3, 'Dilution Solution' => 2]],
            ['date' => '2025-05-31', 'event' => 'added', 'handle' => 'kyanite', 'title' => 'Kyanite & Co'],
        ];

        $html = generateChangesPage($events, ['2024-11-09-recipes.html', '2025-05-31-recipes.html'], 'September 5, 2026');

        $this->assertStringContainsString('<title>Birmingham Ink Recipes - Changes</title>', $html);
        $this->assertStringContainsString('href="../template/styles.css"', $html);
        $this->assertStringContainsString('<h1>Recipe Changes</h1>', $html);
        $this->assertStringContainsString('<a href="../" class="btn btn-icon" title="Recipes">', $html);

        $may = strpos($html, 'May 31, 2025');
        $nov = strpos($html, 'Nov 9, 2024');
        $this->assertNotFalse($may);
        $this->assertNotFalse($nov);
        $this->assertLessThan($nov, $may, 'newest date group comes first');

        $this->assertStringContainsString('<span class="change-type change-added">Added</span>', $html);
        $this->assertStringContainsString('<span class="change-type change-changed">Changed</span>', $html);
        $this->assertStringContainsString('Kyanite &amp; Co', $html);
        $this->assertStringContainsString('<li>Dilution Solution: 1 → 2</li>', $html);
        $this->assertStringContainsString('href="https://www.birminghampens.com/products/abacus"', $html);

        $this->assertStringContainsString('<h2>Legacy snapshots</h2>', $html);
        $this->assertStringContainsString('<a href="2025-05-31-recipes.html">Recipes as of May 31, 2025</a>', $html);
        $legacyFirst = strpos($html, 'href="2025-05-31-recipes.html"');
        $legacySecond = strpos($html, 'href="2024-11-09-recipes.html"');
        $this->assertLessThan($legacySecond, $legacyFirst, 'legacy snapshots newest first');
        $this->assertStringNotContainsString('(Current)', $html);
    }

    public function testGenerateChangesPageHandlesNoEventsAndNoSnapshots(): void
    {
        $html = generateChangesPage([], [], 'September 5, 2026');

        $this->assertStringContainsString('No changes recorded yet.', $html);
        $this->assertStringNotContainsString('<h2>Legacy snapshots</h2>', $html);
    }
```

- [ ] **Step 2: Run to verify failure**

Expected: undefined function `describeFormulaChange`, `generateChangesPage`; archive-link test fails.

- [ ] **Step 3: Implement in `utility/functions.php`**

Update `updatePathsInIndex` replacement strings to `title="Changes"`:
```php
  $updatedContent = str_replace(
    '<a href="index.html" class="btn btn-icon" title="Changes">',
    '<a href="archive/" class="btn btn-icon" title="Changes">',
    $updatedContent
  );
```

Add:
```php
/**
 * Human lines describing how a formula changed. Only differing ingredients.
 *
 * @param array<string, int> $from
 * @param array<string, int> $to
 * @return array<int, string>
 */
function describeFormulaChange(array $from, array $to): array {
  $names = array_unique(array_merge(array_keys($from), array_keys($to)));
  sort($names, SORT_STRING);
  $lines = [];
  foreach ($names as $name) {
    $before = $from[$name] ?? NULL;
    $after = $to[$name] ?? NULL;
    if ($before === $after) {
      continue;
    }
    if ($before === NULL) {
      $lines[] = "$name: added ($after)";
    }
    elseif ($after === NULL) {
      $lines[] = "$name: removed";
    }
    else {
      $lines[] = "$name: $before → $after";
    }
  }
  return $lines;
}

/**
 * Render the Changes page: change-log events newest first, then the frozen
 * legacy snapshot pages.
 *
 * @param array<int, array<string, mixed>> $events  Chronological change-log events.
 * @param array<int, string> $snapshotFiles  Basenames of legacy *-recipes.html files.
 */
function generateChangesPage(array $events, array $snapshotFiles, string $generationDate): string {
  $html = '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<title>Birmingham Ink Recipes - Changes</title>'
    . '<link rel="stylesheet" href="../template/styles.css">'
    . '</head><body>'
    . '<header><div class="header-content"><div class="header-top">'
    . '<div><h1>Recipe Changes</h1><div class="header-date">Generated ' . htmlspecialchars($generationDate) . '</div></div>'
    . '<div class="header-actions"><div class="theme-toggle" id="themeToggle"></div>'
    . '<a href="../" class="btn btn-icon" title="Recipes">🏠</a></div>'
    . '</div></div></header><main class="changes">';

  if ($events === []) {
    $html .= '<p class="changes-empty">No changes recorded yet.</p>';
  }
  else {
    $byDate = [];
    foreach ($events as $event) {
      $byDate[(string) $event['date']][] = $event;
    }
    krsort($byDate, SORT_STRING);
    foreach ($byDate as $date => $dayEvents) {
      $ts = strtotime($date);
      $html .= '<section class="change-day"><h2>' . htmlspecialchars($ts === FALSE ? $date : date('M j, Y', $ts)) . '</h2><ul class="change-list">';
      foreach ($dayEvents as $event) {
        $type = (string) $event['event'];
        $label = ucfirst($type);
        $url = PRODUCT_URL . urlencode((string) $event['handle']);
        $html .= '<li class="change-item"><span class="change-type change-' . htmlspecialchars($type) . '">' . htmlspecialchars($label) . '</span> '
          . '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars((string) $event['title']) . '</a>';
        if ($type === 'changed') {
          /** @var array<string, int> $from */
          $from = $event['from'] ?? [];
          /** @var array<string, int> $to */
          $to = $event['to'] ?? [];
          $html .= '<ul class="change-diff">';
          foreach (describeFormulaChange($from, $to) as $line) {
            $html .= '<li>' . htmlspecialchars($line) . '</li>';
          }
          $html .= '</ul>';
        }
        $html .= '</li>';
      }
      $html .= '</ul></section>';
    }
  }

  if ($snapshotFiles !== []) {
    rsort($snapshotFiles, SORT_STRING);
    $html .= '<section class="legacy"><h2>Legacy snapshots</h2>'
      . '<p>Daily captures of the storefront from before the repository model. Frozen; no new snapshots are written.</p><ul>';
    foreach ($snapshotFiles as $file) {
      if (!preg_match('/(\d{4}-\d{2}-\d{2})/', $file, $m)) {
        continue;
      }
      $ts = strtotime($m[1]);
      $label = $ts === FALSE ? $m[1] : date('M j, Y', $ts);
      $html .= '<li><a href="' . htmlspecialchars($file) . '">Recipes as of ' . htmlspecialchars($label) . '</a></li>';
    }
    $html .= '</ul></section>';
  }

  return $html . '</main><script src="../template/script.js"></script></body></html>';
}
```

- [ ] **Step 4: Create `operations/generate_changes.php`, delete `generate_archive.php`, update `run.php`**

`operations/generate_changes.php`:
```php
<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');
require_once(__DIR__ . '/../utility/recipe_repository.php');

try {
  checkOutputDir(ARCHIVE_DIR);

  $changelog = loadJsonDocument(CHANGELOG_FILE, emptyChangelog());
  /** @var array<int, array<string, mixed>> $events */
  $events = $changelog['events'] ?? [];

  $snapshotFiles = array_map('basename', glob(ARCHIVE_DIR . '/*-recipes.html') ?: []);

  $html = generateChangesPage($events, $snapshotFiles, date('F j, Y'));
  if (file_put_contents(CHANGES_FILE, prettifyHTML($html)) === FALSE) {
    throw new Exception("Failed to write " . CHANGES_FILE);
  }
  echo "Changes page written to " . CHANGES_FILE . " (" . count($events) . " events, " . count($snapshotFiles) . " legacy snapshots)\n";
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
```

Run: `git rm -q operations/generate_archive.php`

`run.php`:
```php
<?php

// Execute operational scripts in sequence
try {
  echo "Fetching products..." . PHP_EOL;
  require_once 'operations/fetch_products.php';

  echo "Processing recipes..." . PHP_EOL;
  require_once 'operations/recipe_extractor.php';

  echo "Merging into repository..." . PHP_EOL;
  require_once 'operations/merge_recipes.php';

  echo "Generating output..." . PHP_EOL;
  require_once 'operations/generate_table.php';

  echo "Generating changes page..." . PHP_EOL;
  require_once 'operations/generate_changes.php';

  echo "Workflow completed successfully!" . PHP_EOL;
}
catch (Throwable $e) {
  fwrite(STDERR, "An error occurred: " . $e->getMessage() . PHP_EOL);
  exit(1);
}
```

- [ ] **Step 5: Add Changes page styles to `output/template/styles.css`** (before `/* Utilities */`)

```css
/* Changes page */
main.changes {
  max-width: 900px;
  margin: 0 auto;
  padding: 2rem 1rem;
}

main.changes h2 {
  font-size: 1.1rem;
  color: var(--text-secondary);
  margin: 2rem 0 0.75rem;
  padding-bottom: 0.35rem;
  border-bottom: 1px solid var(--border-color);
}

.change-list,
.legacy ul {
  list-style: none;
}

.change-item {
  padding: 0.5rem 0;
}

.change-item a,
.legacy a {
  color: var(--text-primary);
  text-decoration: none;
  font-weight: 600;
}

.change-item a:hover,
.legacy a:hover {
  color: var(--accent-primary);
}

.change-type {
  display: inline-block;
  min-width: 5.5rem;
  text-align: center;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  margin-right: 0.5rem;
}

.change-added {
  background: var(--qty-low);
  color: var(--qty-low-text);
}

.change-changed {
  background: var(--qty-medium);
  color: var(--qty-medium-text);
}

.change-diff {
  list-style: none;
  margin: 0.35rem 0 0 6.5rem;
  color: var(--text-secondary);
  font-size: 0.9rem;
}

.legacy p {
  color: var(--text-secondary);
  margin-bottom: 0.75rem;
}

.legacy li {
  padding: 0.25rem 0;
}
```

- [ ] **Step 6: Run tests and static analysis**

Expected: green and clean.

- [ ] **Step 7: Commit**

```bash
git add utility/functions.php operations/generate_changes.php run.php output/template/styles.css tests/FunctionsTest.php
git commit -m "Replace the archive index with a change log page"
```

---

### Task 9: Rebuild the repository from the real archive

**Files:**
- Create: `data/recipes.json`, `data/changelog.json`

- [ ] **Step 1: Export the archive snapshots from `origin/gh-pages` into `var/legacy-archive`**

```bash
mkdir -p var/legacy-archive
for f in $(git ls-tree -r --name-only origin/gh-pages | grep '^archive/.*-recipes\.html$'); do
  git show "origin/gh-pages:$f" > "var/legacy-archive/$(basename "$f")"
done
ls var/legacy-archive | wc -l   # expect 121
```

- [ ] **Step 2: Run the rebuild**

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine php utility/rebuild_from_archive.php var/legacy-archive data | tail -5
```
Expected last line: `Rebuilt 156 recipes (2 currently listed), N change-log events -> /app/data`.

- [ ] **Step 3: Verify against the spec's acceptance checks**

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine php -r '
$r = json_decode(file_get_contents("data/recipes.json"), true)["recipes"];
$c = json_decode(file_get_contents("data/changelog.json"), true)["events"];
$listed = array_keys(array_filter($r, fn($x) => $x["unlisted_on"] === null));
echo "recipes=", count($r), " listed=", implode(",", $listed), PHP_EOL;
$ings = [];
foreach ($r as $x) foreach ($x["components"] as $n => $q) $ings[$n] = true;
echo "ingredients=", count($ings), PHP_EOL;
echo "bad ingredient names: ", implode("|", array_filter(array_keys($ings), fn($n) => $n === "Diluent" || preg_match("/,\s*\d+\s*parts?/i", $n))), PHP_EOL;
$first = array_filter($c, fn($e) => $e["date"] === "2024-11-09");
echo "first-day events=", count($first), " total events=", count($c), PHP_EOL;
echo "unlisted_on histogram: "; $h = []; foreach ($r as $x) $h[$x["unlisted_on"] ?? "listed"] = ($h[$x["unlisted_on"] ?? "listed"] ?? 0) + 1; ksort($h); print_r($h);
'
```
Expected: `recipes=156 listed=aluminum-oxide,cherry-blossom`, `bad ingredient names:` empty, `first-day events=139`.

- [ ] **Step 4: Commit the data**

```bash
git add data/recipes.json data/changelog.json
git commit -m "Seed recipe repository from 121 archive snapshots (2024-11-09 to 2026-08-13)"
```

---

### Task 10: CI, README, and a local end-to-end run

**Files:**
- Modify: `.github/workflows/deploy.yml`
- Modify: `README.md`

- [ ] **Step 1: Rewrite `.github/workflows/deploy.yml`**

```yaml
name: Generate and Deploy Recipes

on:
  schedule:
    - cron: "0 0 * * *"  # Runs daily at midnight UTC
  workflow_dispatch:  # Allows manual triggering from the Actions tab

permissions:
  contents: write  # peaceiris/actions-gh-pages pushes gh-pages with GITHUB_TOKEN

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      # Step 1: Check out gh-pages to recover files that persist across runs
      - name: Check out the gh-pages branch
        uses: actions/checkout@v6
        with:
          ref: gh-pages

      # Step 2: Preserve the frozen legacy snapshots and downloaded images.
      # index.html and archive/index.html are regenerated every run.
      - name: Preserve files to temp storage
        run: |
          mkdir -p /tmp/deploy_files/archive /tmp/deploy_files/images
          if [ -d "archive" ] && [ "$(ls -A archive)" ]; then
            mv archive/*-recipes.html /tmp/deploy_files/archive/ 2>/dev/null || true
          fi
          if [ -d "images" ] && [ "$(ls -A images)" ]; then
            mv images/* /tmp/deploy_files/images/ 2>/dev/null || true
          fi

      # Step 3: Check out main with full history so the data commit can be
      # rebased onto GitLab main if the mirror is behind.
      - name: Check out the main branch
        uses: actions/checkout@v6
        with:
          ref: main
          fetch-depth: 0

      # Step 4: Restore preserved files into output/
      - name: Restore preserved files to output
        run: |
          mkdir -p output/archive output/images
          if [ "$(ls -A /tmp/deploy_files/archive 2>/dev/null)" ]; then
            mv /tmp/deploy_files/archive/* output/archive/ 2>/dev/null || true
          fi
          if [ "$(ls -A /tmp/deploy_files/images 2>/dev/null)" ]; then
            mv /tmp/deploy_files/images/* output/images/ 2>/dev/null || true
          fi

      # Step 5: Set up PHP
      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      # Step 6: Install dev dependencies and run checks
      - name: Install Composer dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run static analysis
        run: ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress

      - name: Run unit tests
        run: ./vendor/bin/phpunit

      # Step 7: Scan the storefront, merge into data/, render output/
      - name: Run the pipeline
        run: php run.php

      # Step 8: Commit repository changes to GitLab main. GitLab is the
      # authoritative copy of main and mirrors to GitHub via push-main-to-github,
      # so the bot never writes GitHub main directly. Requires the GITLAB_URL
      # secret: https://<user>:<deploy-token>@<gitlab-host>/<path>.git with
      # write_repository scope.
      - name: Commit repository changes to GitLab
        env:
          GITLAB_URL: ${{ secrets.GITLAB_URL }}
        run: |
          if [ -z "$(git status --porcelain data/)" ]; then
            echo "No repository changes."
            exit 0
          fi
          if [ -z "$GITLAB_URL" ]; then
            echo "::error::data/ changed but the GITLAB_URL secret is not set. Refusing to publish a page that disagrees with the committed repository."
            git --no-pager diff --stat data/
            exit 1
          fi
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git add data/
          git commit -m "Update recipe repository ($(date -u +%Y-%m-%d))"
          git remote add gitlab "$GITLAB_URL"
          git fetch gitlab main
          git rebase gitlab/main
          git push gitlab HEAD:main
          echo "Pushed $(git rev-parse --short HEAD) to GitLab main."

      # Step 9: Deploy to GitHub Pages (after the data commit so page and data agree)
      - name: Deploy to GitHub Pages
        uses: peaceiris/actions-gh-pages@v4.1.0
        with:
          github_token: ${{ secrets.GITHUB_TOKEN }}
          publish_dir: output
          publish_branch: gh-pages
```

- [ ] **Step 2: Update `README.md`**

Replace the intro paragraph, Architecture, Run in Docker, and Operational notes sections:

```markdown
# Birmingham Recipe Extractor

A simple, unofficial utility that keeps a repository of every ink recipe
[Birmingham Pen Company](https://www.birminghampens.com) has published, and
renders it as a searchable page.

Birmingham removed nearly all recipe content from their storefront in mid-2026.
The committed repository in `data/` holds every recipe observed since
November 2024. The daily scan looks for new or changed recipes on the
storefront and merges them in; recipes the storefront no longer lists stay in
the repository and are shown with a "Not listed since" badge.

## Architecture

- **Repository on `main`** — `data/recipes.json` (one entry per product handle:
  title, image, formula, `first_seen`, `unlisted_on`) and `data/changelog.json`
  (append-only `added` / `changed` events). Both are sorted for stable diffs.
- **Source on `main`** — PHP scripts under `operations/`, shared helpers in
  `utility/`, entry point `run.php`. The pipeline is: fetch products →
  extract recipes → merge into the repository → render `index.html` →
  render the Changes page.
- **Daily run on GitHub Actions** — `.github/workflows/deploy.yml` runs
  `phpstan` → `phpunit` → `php run.php` daily at 00:00 UTC. If `data/`
  changed, it commits and pushes to GitLab `main` (the authoritative copy,
  via the `GITLAB_URL` secret), then publishes `output/` to `gh-pages`.
- **Mirrors via GitLab CI** — `.gitlab-ci.yml` mirrors `main` from GitLab to
  GitHub on every commit, mirrors `gh-pages` from GitHub back to GitLab, and
  serves GitLab Pages from the mirrored `gh-pages` branch.
- **Output** — `index.html` shows the whole repository. `archive/index.html`
  is the change log plus links to the frozen legacy snapshots
  (`archive/*-recipes.html`, Nov 2024 – Aug 2026). No new snapshots are
  written.
```

Replace the Docker run command with:
```sh
docker run --rm -v "$PWD/output:/app/output" -v "$PWD/data:/app/data" birmingham-recipe-extractor
```

Replace Operational notes with:
```markdown
## Operational notes

- A product-list page that fails after all retries, or a product list with
  zero products, aborts the run before the merge. A partial list would read as
  "these recipes are gone".
- A recipe page that fails to fetch is reported as `recipe_fetch_failed` and
  the merge leaves that recipe untouched. Only recipes genuinely absent from a
  successful scan get `unlisted_on` set.
- Listing flips are not change-log events; only `added` and formula `changed`
  are. Listing state lives in `unlisted_on`.
- `index.html` and `archive/index.html` are regenerated every run, so
  `gh-pages` receives a small commit daily carrying the "Last checked" date.
- Rebuilding the repository from the legacy snapshots (normally never needed):
  `php utility/rebuild_from_archive.php <dir-of-*-recipes.html> data`.
- Secret required on GitHub: `GITLAB_URL` =
  `https://<user>:<deploy-token>@<gitlab-host>/<group>/<project>.git` with
  `write_repository` scope. Without it, a run that changes `data/` fails
  rather than publish a page that disagrees with the committed repository.
```

- [ ] **Step 3: Local end-to-end run against the live storefront**

```bash
mkdir -p output/archive output/images
cp var/legacy-archive/*.html output/archive/
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine php run.php 2>&1 | tail -25
git status --porcelain data/
```
Expected: run completes; the merge reports 2 recipes on site (Aluminum Oxide, Cherry Blossom) and no repository changes, so `git status` shows `data/` clean. `output/index.html` exists with 156 recipes; `output/archive/index.html` exists. Open `output/index.html` and `output/archive/index.html` locally and eyeball the badge and the change log.

If the live scan shows a legitimate difference from the 2026-08-13 snapshot (Birmingham changed something since), the merge will write `data/`. Review the diff; if it is real, keep it as part of this commit.

- [ ] **Step 4: Clean up and commit**

```bash
rm -f output/archive/*-recipes.html output/index.html output/archive/index.html
git add .github/workflows/deploy.yml README.md
git commit -m "Commit repository changes to GitLab from the daily run; document the new model"
```

---

### Task 11: Final verification and handoff

- [ ] **Step 1: Full suite and static analysis one last time**

```bash
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli-alpine sh -c './vendor/bin/phpunit && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
```

- [ ] **Step 2: Confirm the tree is clean and list the commits**

```bash
git status --porcelain
git log --oneline ffa4183..HEAD
```

- [ ] **Step 3: Report to the user**

Include: the acceptance numbers from Task 9, the one manual step remaining (create the GitLab deploy token and add `GITLAB_URL` to GitHub secrets), and that the first `workflow_dispatch` run should be watched to confirm the GitLab push and mirror.

---

### Task 12: Upgrade the toolchain to PHP 8.5

Requested during planning. PHP 8.3 has been security-only since December 2025. The suite and PHPStan were verified clean under PHP 8.5.10 before this task was added.

**Files:**
- Modify: `composer.json` (`"php": ">=8.5 <9.0"`)
- Modify: `.github/workflows/deploy.yml` (`php-version: '8.5'`)
- Modify: `.gitlab-ci.yml` (both `php:8.3-cli-alpine` → `php:8.5-cli-alpine`)
- Modify: `Dockerfile` (`FROM php:8.5-cli-alpine`)
- Modify: `README.md` ("PHP 8.5+ required. CI pins PHP 8.5.")

- [ ] **Step 1: Make the five edits above**

- [ ] **Step 2: Regenerate the lock file platform check and run the suite under 8.5**

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 composer update --lock --no-interaction --quiet
docker run --rm -v "$PWD:/app" -w /app php:8.5-cli-alpine sh -c 'php -v | head -1 && ./vendor/bin/phpunit && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress'
docker build -q -t birmingham-recipe-extractor:php85 . && docker run --rm birmingham-recipe-extractor:php85 php -v | head -1
```
Expected: green, clean, and the image reports PHP 8.5.

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock .github/workflows/deploy.yml .gitlab-ci.yml Dockerfile README.md
git commit -m "Upgrade toolchain to PHP 8.5"
```
