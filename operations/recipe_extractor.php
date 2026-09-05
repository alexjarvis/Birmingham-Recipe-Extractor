<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

const RECIPE_FETCH_INTER_REQUEST_DELAY_MICROSECONDS = 250000; // 250ms politeness delay

try {
  checkInputFile(PRODUCTS_FILE);
  $products = loadProducts(PRODUCTS_FILE);

  echo "Processing " . count($products) . " products...\n";
  $enrichedProducts = [];
  $recipesFound = 0;
  $taggedRecipeCount = 0;
  $fetchFailures = [];

  foreach ($products as $product) {
    $price = $product['variants'][0]['price'];
    $compareAtPrice = $product['variants'][0]['compare_at_price'];
    $available = $product['variants'][0]['available'];

    $enrichedProduct = [
      'id' => $product['id'],
      'title' => $product['title'],
      'handle' => $product['handle'],
      'price' => $price,
      'on_sale' => $compareAtPrice > $price,
      'sold_out' => !$available,
      'tags' => $product['tags'],
      'images' => $product['images'],
      'vendor' => $product['vendor'],
      'product_type' => $product['product_type'],
      'variants' => $product['variants'],
      'body_html' => $product['body_html'],
      'recipe_fetch_failed' => FALSE,
    ];

    $recipeHtml = '';

    if (strpos($product['body_html'], 'Ink Recipe') !== FALSE) {
      // Special case: recipe lives directly in the product description.
      $recipeHtml = $product['body_html'];
    }
    elseif (in_array('recipe', $product['tags'])) {
      $taggedRecipeCount++;
      $pageHtml = fetchProductPage(PRODUCT_URL . $product['handle']);

      if ($pageHtml === NULL) {
        // The merge treats a failed fetch as "unknown", not "gone".
        $enrichedProduct['recipe_fetch_failed'] = TRUE;
        $fetchFailures[] = $product['title'];
        echo "  ✗ " . $product['title'] . " (fetch failed after " . RECIPE_FETCH_MAX_ATTEMPTS . " attempts)\n";
      }
      else {
        $recipeHtml = extractRecipeHtmlFromPage($pageHtml);
      }

      usleep(RECIPE_FETCH_INTER_REQUEST_DELAY_MICROSECONDS);
    }

    $enrichedProduct['recipe'] = trim($recipeHtml);
    $enrichedProduct['recipe_components'] = parseRecipeComponents($recipeHtml);

    if (!empty($enrichedProduct['recipe_components'])) {
      $recipesFound++;
      echo "  ✓ " . $product['title'] . " (recipe with " . count($enrichedProduct['recipe_components']) . " ingredients)\n";
    }
    $enrichedProducts[] = $enrichedProduct;
  }

  echo "\nSummary:\n";
  echo "  Total products processed: " . count($products) . "\n";
  echo "  Products with recipes: $recipesFound\n";
  echo "  Recipe-tagged products: $taggedRecipeCount\n";
  if (!empty($fetchFailures)) {
    echo "  Fetch failures (left unchanged in the repository): " . implode(', ', $fetchFailures) . "\n";
  }

  file_put_contents(ENRICHED_PRODUCTS_FILE, json_encode($enrichedProducts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
  echo "  Enriched data written to " . ENRICHED_PRODUCTS_FILE . PHP_EOL;
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
