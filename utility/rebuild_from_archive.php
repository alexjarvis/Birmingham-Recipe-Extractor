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
