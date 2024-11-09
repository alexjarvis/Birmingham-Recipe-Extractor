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
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}

/**
 * Extracts the HTML content of the <table> element from a given HTML file.
 *
 * @param string $filePath The path to the HTML file.
 *
 * @return string The HTML content of the <table> element.
 */
function extractTableContent(string $filePath): string {
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE); // Suppress warnings for invalid HTML
  $dom->loadHTMLFile($filePath);
  libxml_clear_errors();

  // Find the table element
  $table = $dom->getElementsByTagName('table')->item(0);

  // Return the table HTML as a string, or an empty string if not found
  return $table ? $dom->saveHTML($table) : '';
}