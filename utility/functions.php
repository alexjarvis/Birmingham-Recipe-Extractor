<?php

/**
 * Checks for the existence of output directory and creates it if necessary.
 *
 * @return void
 * @throws \Exception
 */
function checkOutputDir($config) {
  if (!is_dir($config['OUTPUT_DIR'])) {
    if (!mkdir($config['OUTPUT_DIR'], 0777, TRUE) && !is_dir($config['OUTPUT_DIR'])) {
      throw new Exception("Failed to create output directory: " . $config['OUTPUT_DIR']);
    }
  }
}

/**
 * @param $path
 *
 * @return void
 * @throws \Exception
 */
function checkInputFile($path) {
  if (!is_file($path)) {
    throw new Exception("Failed to load input file: " . $path);
  }
}

/**
 * Create a reusable HTTP context.
 *
 * @return resource
 */
function createHttpContext() {
  return stream_context_create([
    'http' => [
      'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
    ],
  ]);
}

/**
 * Fetch a single page of products
 *
 * @param $page
 * @param $config
 *
 * @return array
 * @throws \Exception
 */
function fetchPage($page, $config): array {
  $retries = 0;
  $context = createHttpContext();

  while ($retries < $config['FETCH_MAX_RETRIES']) {
    try {
      $url = $config['PRODUCTS_URL'] . '?page=' . $page . '&FETCH_LIMIT=' . $config['FETCH_LIMIT'];
      $response = file_get_contents($url, FALSE, $context);

      if ($response === FALSE) {
        throw new Exception("Failed to fetch URL: $url");
      }

      $data = json_decode($response, TRUE, 512, JSON_THROW_ON_ERROR);
      return $data['products'] ?? [];
    }
    catch (Exception $e) {
      $retries++;
      echo "Attempt $retries failed for page $page: " . $e->getMessage() . "\n";
      if ($retries >= $config['FETCH_MAX_RETRIES']) {
        throw new Exception("Max retries reached for page $page: " . $e->getMessage());
      }
      sleep(pow(2, $retries)); // Exponential backoff
    }
  }

  return [];
}

/**
 * @param $config
 *
 * @return array
 */
function fetchAllProducts($config): array {
  $allProducts = [];
  $page = 1;

  while (TRUE) {
    try {
      $products = fetchPage($page, $config);
      if (empty($products)) {
        break;
      }

      $allProducts = array_merge($allProducts, $products);
      $page++;
    }
    catch (Exception $e) {
      echo "Error fetching products: " . $e->getMessage() . "\n";
      break;
    }
  }

  return $allProducts;
}

/**
 * Correct known recipe typos.
 *
 * @param $name
 *
 * @return string
 */
function correctTypos($name): string {
  $corrections = [
    'Saltwater Taffy' => 'Salt Water Taffy',
    'Sterling Siver' => 'Sterling Silver',
    'Tiger Lil' => 'Tiger Lily',
    'Teaberry Ice Crea' => 'Teaberry Ice Cream',
  ];

  return $corrections[$name] ?? $name; // Replace if found, otherwise return original
}

// Load and validate JSON data
function loadProducts($filePath): array {
  try {
    checkInputFile($filePath);
  }
  catch (Exception $e) {
    throw $e;
  }

  $jsonData = file_get_contents($filePath);
  $products = json_decode($jsonData, TRUE);

  if (!is_array($products)) {
    throw new Exception("Invalid or missing JSON data in $filePath");
  }

  return $products;
}

// Process products to build necessary data structures
function processProducts($products): array {
  $enrichedProducts = [];
  $ingredientTotals = [];
  $productImages = [];

  foreach ($products as $product) {
    // Capture main image for the product
    if (!empty($product['images'][0]['src'])) {
      $productImages[$product['title']] = $product['images'][0]['src'];
    }

    // Collect ingredients and quantities
    if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
      $enrichedProducts[] = $product;
      foreach ($product['recipe_components'] as $ingredient => $quantity) {
        $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
      }
    }
  }

  // Sort products by title
  usort($enrichedProducts, fn($a, $b) => strcmp($a['title'], $b['title']));

  return [$enrichedProducts, $ingredientTotals, $productImages];
}

// Generate HTML header for the table
function generateTableHeader($allIngredients, $productImages): string {
  $headerHtml = '<thead><tr><th>Product/Ingredients</th>';
  foreach ($allIngredients as $ingredient) {
    $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
    $headerHtml .= '<th><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient);

    if (isset($productImages[$ingredient])) {
      $headerHtml .= '<img src="' . htmlspecialchars($productImages[$ingredient]) . '" alt="' . htmlspecialchars($ingredient) . '" class="ingredient-img">';
    }

    $headerHtml .= '</a></th>';
  }
  $headerHtml .= '</tr></thead>';
  return $headerHtml;
}

// Generate footer row with counts
function generateFooterRow($label, $data): string {
  $rowHtml = "<tr><td>$label</td>";
  foreach ($data as $value) {
    $rowHtml .= '<td>' . htmlspecialchars($value) . '</td>';
  }
  $rowHtml .= '</tr>';
  return $rowHtml;
}

// Generate HTML footer for Recipe Count and Quantity Count
function generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals): string {
  $footerHtml = '<tfoot>';

  // Recipe Count Row
  $recipeCounts = array_map(function($ingredient) use ($enrichedProducts) {
    return count(array_filter($enrichedProducts, fn($product) => isset($product['recipe_components'][$ingredient])));
  }, $allIngredients);
  $footerHtml .= generateFooterRow("Recipe Count", $recipeCounts);

  // Quantity Count Row
  $quantityCounts = array_map(fn($ingredient) => $ingredientTotals[$ingredient] ?? 0, $allIngredients);
  $footerHtml .= generateFooterRow("Quantity Count", $quantityCounts);

  $footerHtml .= '</tfoot>';
  return $footerHtml;
}

// Generate HTML for the complete table
function generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages): string {
  $generationDate = date('F j, Y');
  $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Birmingham Ink Recipes as of ' . $generationDate . '</title>';
  $html .= '<link rel="stylesheet" href="../template/styles.css">';
  $html .= '</head><body>';
  $html .= '<header><h1>Birmingham Ink Recipes as of ' . $generationDate . '</h1></header>';
  $html .= '<main><table>';

  // Generate table header and footer
  $html .= generateTableHeader($allIngredients, $productImages);

  // Table body with product data
  $html .= '<tbody>';
  foreach ($enrichedProducts as $product) {
    $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
    $productImage = $productImages[$product['title']] ?? 'path/to/fallback_image.jpg';

    $html .= '<tr><td><div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';
    if ($productImage) {
      $html .= '<img src="' . htmlspecialchars($productImage) . '" alt="' . htmlspecialchars($product['title']) . '" class="product-img">';
    }
    $html .= '</td>';

    foreach ($allIngredients as $ingredient) {
      $html .= '<td>' . ($product['recipe_components'][$ingredient] ?? '') . '</td>';
    }
    $html .= '</tr>';
  }
  $html .= '</tbody>';

  // Add footer with counts
  $html .= generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);
  $html .= '</table></main>';

  // Footer and script for table sorting
  $html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer>';
  $html .= '<script src="../template/script.js"></script>';
  $html .= '</body></html>';
  return $html;
}
