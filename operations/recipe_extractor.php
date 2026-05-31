<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

const RECIPE_CAPTURE_MIN_RATIO = 0.80;
const RECIPE_FETCH_INTER_REQUEST_DELAY_MICROSECONDS = 250000; // 250ms politeness delay

try {
  checkInputFile(PRODUCTS_FILE);

  // Load JSON file containing products
  $jsonData = file_get_contents(PRODUCTS_FILE);
  $products = json_decode($jsonData, TRUE);

  // Check if data is valid
  if (!is_array($products)) {
    die("Invalid products.json data." . PHP_EOL);
  }

  echo "Processing " . count($products) . " products...\n";
  $enrichedProducts = [];
  $recipesFound = 0;
  $taggedRecipeCount = 0;
  $fetchSuccessCount = 0;
  $fetchFailures = [];

  foreach ($products as $product) {
    // Extract basic information
    $price = $product['variants'][0]['price'];
    $compareAtPrice = $product['variants'][0]['compare_at_price'];
    $available = $product['variants'][0]['available'];

    // Determine if product is on sale or sold out
    $isOnSale = $compareAtPrice > $price;
    $isSoldOut = !$available;

    // Enriched product data
    $enrichedProduct = [
      'id' => $product['id'],
      'title' => $product['title'],
      'handle' => $product['handle'],
      'price' => $price,
      'on_sale' => $isOnSale,
      'sold_out' => $isSoldOut,
      'tags' => $product['tags'],
      'images' => $product['images'],
      'vendor' => $product['vendor'],
      'product_type' => $product['product_type'],
      'variants' => $product['variants'],
      'body_html' => $product['body_html'],
    ];

    $recipeHtml = '';

    if (strpos($product['body_html'], 'Ink Recipe') !== FALSE) {
      // Special case: recipe lives directly in the product description.
      $recipeHtml = $product['body_html'];
    }
    elseif (in_array('recipe', $product['tags'])) {
      $taggedRecipeCount++;
      $recipeUrl = PRODUCT_URL . $product['handle'];

      $pageHtml = fetchProductPage($recipeUrl);

      if ($pageHtml === NULL) {
        $fetchFailures[] = $product['title'];
        echo "  ✗ " . $product['title'] . " (fetch failed after " . RECIPE_FETCH_MAX_ATTEMPTS . " attempts)\n";
      }
      else {
        $fetchSuccessCount++;
        $recipeHtml = extractRecipeHtmlFromPage($pageHtml);
      }

      // Politeness delay between sequential requests to avoid tripping rate limits.
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

  file_put_contents(ENRICHED_PRODUCTS_FILE, json_encode($enrichedProducts, JSON_PRETTY_PRINT));

  echo "\nSummary:\n";
  echo "  Total products processed: " . count($products) . "\n";
  echo "  Products with recipes: $recipesFound\n";
  if ($taggedRecipeCount > 0) {
    $rate = $fetchSuccessCount / $taggedRecipeCount;
    echo "  Recipe-tagged products fetched: $fetchSuccessCount/$taggedRecipeCount (" . round($rate * 100, 1) . "%)\n";
    if (!empty($fetchFailures)) {
      echo "  Failed handles: " . implode(', ', $fetchFailures) . "\n";
    }
    if ($rate < RECIPE_CAPTURE_MIN_RATIO) {
      throw new RuntimeException(sprintf(
        'Capture rate %.1f%% below minimum %.0f%% (%d/%d recipe-tagged products fetched). Refusing to publish a degraded snapshot.',
        $rate * 100,
        RECIPE_CAPTURE_MIN_RATIO * 100,
        $fetchSuccessCount,
        $taggedRecipeCount
      ));
    }
  }
  echo "  Enriched data written to " . ENRICHED_PRODUCTS_FILE . PHP_EOL;
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
