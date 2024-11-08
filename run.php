<?php

// Execute operational scripts in sequence
try {
  echo "Fetching products..." . PHP_EOL;
  require_once 'operations/fetch_products.php';

  echo "Processing recipes..." . PHP_EOL;
  require_once 'operations/recipe_extractor.php';

    echo "Generating output..." . PHP_EOL;
    require_once 'operations/generate_table.php';

    echo "Workflow completed successfully!" . PHP_EOL;
}
catch (Exception $e) {
  // Handle exceptions and log errors
  file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
  echo "An error occurred: " . $e->getMessage() . PHP_EOL;
}
