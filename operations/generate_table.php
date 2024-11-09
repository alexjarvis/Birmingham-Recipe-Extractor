<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

// Main execution
try {
  // Ensure input file exists
  checkInputFile(ENRICHED_PRODUCTS_FILE);

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

  // Generate the HTML content
  $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages);

  // Prettify the HTML output
  $prettyHtml = prettifyHTML($html);

  // Write the new HTML to the archive
  $archiveFile = ARCHIVE_FILE;
  file_put_contents($archiveFile, $prettyHtml);

  // Check if output/index.html exists
  $indexFile = INDEX_FILE;
  if (file_exists($indexFile)) {
    // Extract table content from both files for comparison
    $newTableContent = extractTableContent($archiveFile);
    $existingTableContent = extractTableContent($indexFile);

    // Compare the table content
    if ($newTableContent !== $existingTableContent) {
      // Table content differs, update index.html
      copy($archiveFile, $indexFile);
      echo "Updated index.html with new recipe data.\n";
    }
    else {
      // Table content is identical, delete the newly generated archive file
      unlink($archiveFile);
      echo "No changes detected; deleted the new archive file.\n";
    }
  }
  else {
    // index.html doesn't exist, so we use the new file as the index
    copy($archiveFile, $indexFile);
    echo "index.html created from new recipe data.\n";
  }

  if (PURGE) {
    unlink(ENRICHED_PRODUCTS_FILE);
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
