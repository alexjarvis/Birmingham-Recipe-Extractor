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

  // Normalize paths in table content for comparison (remove ../ prefixes)
  // This ensures we compare actual recipe data, not just path differences
  $newTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $newTableContent);
  $existingTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $existingTableContent);

  // Compare the table content to determine if we should update index.html
  if ($newTableNormalized !== $existingTableNormalized || empty($existingTableNormalized)) {
    // Content has changed or index.html doesn't exist - update it
    copy($archiveFile, $indexFile);
    updatePathsInIndex($indexFile); // Call the function to adjust paths in index.html
    echo "✓ index.html updated with new recipe data (content changed).\n";
    echo "✓ New archive file retained: " . basename($archiveFile) . "\n";
  }
  else {
    // Content is identical - don't update index.html
    echo "✗ No recipe changes detected (table content identical).\n";

    // Always delete the redundant archive file when content is identical
    // The index.html already contains this content, so no need for a duplicate archive entry
    unlink($archiveFile);
    unlink(ENRICHED_PRODUCTS_FILE);
    unlink(PRODUCTS_FILE);
    echo "✗ Deleted redundant files (archive, products JSON, enriched JSON).\n";
    echo "✓ index.html preserved unchanged.\n";
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
