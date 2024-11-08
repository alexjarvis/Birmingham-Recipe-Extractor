<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

try {
  global $config;
  checkInputFile($config['PRODUCTS_FILE']);

  // Load JSON file containing products
  $jsonData = file_get_contents($config['PRODUCTS_FILE']);
  $products = json_decode($jsonData, TRUE);

  // Check if data is valid
  if ($products === NULL || !is_array($products)) {
    die("Invalid products.json data.\n");
  }

  $enrichedProducts = [];

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

    // Initialize recipe variables
    $recipeHtml = '';
    $recipeComponents = [];

    // Check if "Ink Recipe" appears in body_html for special cases
    if (strpos($product['body_html'], 'Ink Recipe') !== FALSE) {
      // Special case: Extract recipe from body_html
      $recipeHtml = $product['body_html'];
    }
    elseif (in_array('recipe', $product['tags'])) {
      // Standard case: Fetch the recipe from the product page if tagged as "recipe"
      $handle = $product['handle'];
      $recipeUrl = $config['PRODUCT_URL'] . $handle;

      // Use cURL to fetch the product page
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $recipeUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
      $html = curl_exec($ch);
      curl_close($ch);

      if ($html !== FALSE) {
        // Extract recipe content from the product page
        $dom = new DOMDocument();
        libxml_use_internal_errors(TRUE);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $recipeNode = $xpath->query("//div[contains(@class, 'metafield-rich_text_field')]");

        if ($recipeNode->length > 0) {
          foreach ($recipeNode[0]->childNodes as $child) {
            $recipeHtml .= $dom->saveHTML($child);
          }
        }
      }
    }

    // Store the original recipe HTML
    $enrichedProduct['recipe'] = trim($recipeHtml);

    // In the main processing loop where you store recipe components
    if ($recipeHtml) {
      // Normalize non-breaking spaces
      $recipeHtml = str_replace("\xc2\xa0", ' ', $recipeHtml);

      // Enhanced regex pattern to capture all possible formats
      if (preg_match_all('/(?:<strong>\s*(\d+)\s*<\/strong>\s*|\+?\s*(\d+)\s*)\s*parts?\s*(?:<a[^>]*>)?\s*([^<\n]+?)(?:<\/a>)?\s*(?=<\/p>|<br>|$)/i', $recipeHtml, $matches)) {
        foreach ($matches[3] as $index => $name) {
          // Use the first non-empty quantity from the matches
          $quantity = (int) ($matches[1][$index] ?: $matches[2][$index]);

          // Clean up the ingredient name
          $name = correctTypos(trim(html_entity_decode(strip_tags($name))));

          $recipeComponents[$name] = $quantity;  // Store in components array
        }
      }
    }

    // Assign parsed components to the enriched product
    $enrichedProduct['recipe_components'] = $recipeComponents;

    // Add enriched product to the results array
    echo $product['title'] . PHP_EOL;
    $enrichedProducts[] = $enrichedProduct;
  }

  // Write the enriched data to products_enriched.json
  file_put_contents($config['ENRICHED_PRODUCTS_FILE'], json_encode($enrichedProducts, JSON_PRETTY_PRINT));

  echo "Enriched data written to " . $config['ENRICHED_PRODUCTS_FILE'] . PHP_EOL;
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
}
