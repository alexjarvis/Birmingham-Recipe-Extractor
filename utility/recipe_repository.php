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
    /** @var array<string, int> $existingComponents */
    $existingComponents = $existing['components'] ?? [];
    $existing['components'] = sortComponents($existingComponents);
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
