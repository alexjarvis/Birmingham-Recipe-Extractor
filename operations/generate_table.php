<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

// Main execution
try {
  global $config;

  checkInputFile($config['ENRICHED_PRODUCTS_FILE']);

  $products = loadProducts($config['ENRICHED_PRODUCTS_FILE']);
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

  // Save the prettified HTML file
  if (file_put_contents($config['TABLE_FILE'], $prettyHtml) !== FALSE) {
    echo "HTML table written to " . $config['TABLE_FILE'] . PHP_EOL;
  }
  else {
    echo "Failed to write to " . $config['TABLE_FILE'] . PHP_EOL;
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
}