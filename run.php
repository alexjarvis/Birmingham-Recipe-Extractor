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
