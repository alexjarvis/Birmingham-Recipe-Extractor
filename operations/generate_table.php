<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

// Main execution
try {
  // Ensure necessary directories and files exist
  checkInputFile(ENRICHED_PRODUCTS_FILE);
  checkOutputDir(ARCHIVE_DIR);
  checkOutputDir(CURRENT_DIR);
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

  // Generate the HTML content
  $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages);

  // Prettify the HTML output
  $prettyHtml = prettifyHTML($html);

  // Write the new HTML to the archive
  $archiveFile = ARCHIVE_FILE;
  file_put_contents($archiveFile, $prettyHtml);

  $indexFile = INDEX_FILE;

  // Extract table content from both files for comparison
  $newTableContent = extractTableContent($archiveFile);
  $existingTableContent = extractTableContent($indexFile);

  // Update index.html with the new archive file
  copy($archiveFile, $indexFile);
  echo "index.html updated with new recipe data.\n";

  // Get a list of existing files in the archive
  $archiveFiles = glob(ARCHIVE_DIR . '/*-recipes.html');

  // Compare the table content to determine if the archive file should be deleted
  if ($newTableContent === $existingTableContent && count($archiveFiles) > 1) {
    // The new file is identical to the docs `index.html`, and there are other archive files
    unlink($archiveFile);
    unlink(ENRICHED_PRODUCTS_FILE);
    unlink(PRODUCTS_FILE);
    echo "No changes detected; deleted the new archive file.\n";
  }
  else {
    echo "New archive file retained as unique or only file in archive.\n";
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
