<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

// Runs last, after every page is written: an image no page references is
// deleted, so it drops out of gh-pages on the next publish (LAB-88).
try {
  // The frozen legacy snapshots reference images too. Without them (restore
  // from gh-pages failed) their images would look unused, so don't prune.
  if (glob(ARCHIVE_DIR . '/*-recipes.html') === [] || !is_file(INDEX_FILE)) {
    echo "Skipping image prune: no legacy snapshots or index.html in " . OUTPUT_DIR . PHP_EOL;
    return;
  }

  $deleted = pruneUnreferencedImages(OUTPUT_DIR, IMAGE_DIR);
  echo "Pruned " . count($deleted) . " unreferenced images" . PHP_EOL;
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
  throw $e;
}
