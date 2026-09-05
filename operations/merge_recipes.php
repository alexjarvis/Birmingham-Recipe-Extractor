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
