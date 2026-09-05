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
