<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

// Main execution
try {
  // Ensure necessary directories and files exist
  checkInputFile(ENRICHED_PRODUCTS_FILE);
  checkOutputDir(ARCHIVE_DIR);
  checkOutputDir(OUTPUT_DIR);
  checkOutputDir(IMAGE_DIR);

  // Load products and process
  $products = loadProducts(ENRICHED_PRODUCTS_FILE);
  [
    $enrichedProducts,
    $ingredientTotals,
    $productImages,
  ] = processProducts($products);

  // Gather all unique ingredients sorted alphabetically
  $allIngredients = array_keys($ingredientTotals);
  sort($allIngredients);

  echo "Products with recipes: " . count($enrichedProducts) . "\n";
  echo "Unique ingredients: " . count($allIngredients) . "\n";

  // Generate the HTML content
  $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages);

  // Prettify the HTML output
  $prettyHtml = prettifyHTML($html);

  // Write the new HTML to the archive
  $archiveFile = ARCHIVE_FILE;
  file_put_contents($archiveFile, $prettyHtml);
  echo "Created archive file: $archiveFile\n";

  $indexFile = INDEX_FILE;

  // Extract table content from both files for comparison
  $newTableContent = extractTableContent($archiveFile);
  $existingTableContent = file_exists($indexFile) ? extractTableContent($indexFile) : '';

  // Get a list of existing files in the archive
  $archiveFiles = glob(ARCHIVE_DIR . '/*-recipes.html');

  // Compare the table content to determine if we should update index.html
  if ($newTableContent !== $existingTableContent || empty($existingTableContent)) {
    // Content has changed or index.html doesn't exist - update it
    copy($archiveFile, $indexFile);
    updatePathsInIndex($indexFile); // Call the function to adjust paths in index.html
    echo "✓ index.html updated with new recipe data (content changed).\n";
    echo "✓ New archive file retained: " . basename($archiveFile) . "\n";
  }
  else {
    // Content is identical - don't update index.html
    echo "✗ No recipe changes detected (table content identical).\n";

    if (count($archiveFiles) > 1) {
      // Delete the redundant archive file and JSON files
      unlink($archiveFile);
      unlink(ENRICHED_PRODUCTS_FILE);
      unlink(PRODUCTS_FILE);
      echo "✗ Deleted redundant files (archive, products JSON, enriched JSON).\n";
      echo "✓ index.html preserved unchanged.\n";
    }
    else {
      // Keep the file if it's the only archive
      echo "✓ Archive file retained as only file in archive.\n";
    }
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
