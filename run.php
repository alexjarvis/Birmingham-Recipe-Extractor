<?php

// Execute operational scripts in sequence
try {
  echo "Fetching products..." . PHP_EOL;
  require_once 'operations/fetch_products.php';

  echo "Processing recipes..." . PHP_EOL;
  require_once 'operations/recipe_extractor.php';

  echo "Generating output..." . PHP_EOL;
  require_once 'operations/generate_table.php';

  echo "Generating archive..." . PHP_EOL;
  require_once 'operations/generate_archive.php';

  echo "Workflow completed successfully!" . PHP_EOL;
}
catch (Exception $e) {
  echo "An error occurred: " . $e->getMessage() . PHP_EOL;
}
