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

  // Total products that should have a recipe (recipe-tagged or "Ink Recipe" in body_html).
  // Used as the denominator for the capture-rate stat — the numerator is what we actually extracted.
  $totalTagged = count(array_filter($products, fn($p) =>
    in_array('recipe', $p['tags'] ?? [], TRUE)
    || strpos($p['body_html'] ?? '', 'Ink Recipe') !== FALSE
  ));

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
  $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages, $totalTagged);

  // Prettify the HTML output
  $prettyHtml = prettifyHTML($html);

  $archiveFile = ARCHIVE_FILE;
  $indexFile = INDEX_FILE;

  // Compare in-memory first — only persist when the table content has actually
  // changed, avoiding the wasteful write+delete cycle on no-op days.
  $newTable = extractTableContentFromString($prettyHtml);
  $existingTable = file_exists($indexFile) ? extractTableContent($indexFile) : '';

  $newTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $newTable);
  $existingTableNormalized = str_replace(['../images', '../template'], ['images', 'template'], $existingTable);

  if ($newTableNormalized !== $existingTableNormalized || empty($existingTableNormalized)) {
    // Content has changed (or no index yet) — persist the new archive and update index.
    file_put_contents($archiveFile, $prettyHtml);
    copy($archiveFile, $indexFile);
    updatePathsInIndex($indexFile);
    echo "✓ Created archive file: $archiveFile\n";
    echo "✓ index.html updated with new recipe data (content changed).\n";
  }
  else {
    // Content is identical — don't write anything; clean up generated inputs that we'd otherwise leak.
    echo "✗ No recipe changes detected (table content identical).\n";
    @unlink(ENRICHED_PRODUCTS_FILE);
    @unlink(PRODUCTS_FILE);
    echo "✗ Deleted redundant inputs (products JSON, enriched JSON).\n";
    echo "✓ index.html preserved unchanged.\n";
  }
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
  throw $e;
}
