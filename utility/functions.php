<?php

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
 * Checks for the existence of output directory and creates it if necessary.
 *
 * @return void
 * @throws \Exception
 */
function checkOutputDir($dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      throw new Exception("Failed to create output directory: " . $dir);
    }
  }
}

/**
 * Helper function to clean the image name by removing query parameters
 *
 * @param $imageUrl
 *
 * @return string
 */
function cleanImageName($imageUrl): string {
  // Parse the URL to extract the path and remove query parameters
  $urlParts = parse_url($imageUrl);
  return basename($urlParts['path']); // Returns just the filename without query params
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
    'Diluent' => 'Dilution Solution',
    'Dilution' => 'Dilution Solution',
  ];

  return $corrections[$name] ?? $name; // Replace if found, otherwise return original
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
 * Helper function to download images if they don't already exist
 *
 * @param $imageUrl
 * @param $imagePath
 *
 * @return void
 */
function downloadImageIfNeeded($imageUrl, $imagePath) {
  if (!file_exists($imagePath)) {
    try {
      $imageData = file_get_contents($imageUrl);
      if ($imageData === FALSE) {
        throw new Exception("Failed to download image: $imageUrl");
      }
      file_put_contents($imagePath, $imageData);
      echo "Downloaded: $imagePath" . PHP_EOL;
    }
    catch (Exception $e) {
      echo "Error downloading image: " . $e->getMessage() . PHP_EOL;
    }
  }
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

/**
 * @return array
 */
function fetchAllProducts(): array {
  $allProducts = [];
  $page = 1;

  while (TRUE) {
    try {
      $products = fetchPage($page);
      if (empty($products)) {
        echo "No more products found on page $page. Stopping." . PHP_EOL;
        break;
      }

      $productCount = count($products);
      echo "Retrieved $productCount products from page $page" . PHP_EOL;
      $allProducts = array_merge($allProducts, $products);
      $page++;
    }
    catch (Exception $e) {
      echo "Error fetching products: " . $e->getMessage() . PHP_EOL;
      break;
    }
  }

  $totalProducts = count($allProducts);
  echo "Total products fetched: $totalProducts" . PHP_EOL;
  return $allProducts;
}

/**
 * Fetch a single page of products
 *
 * @param $page
 *
 * @return array
 * @throws \Exception
 */
function fetchPage($page): array {
  $retries = 0;
  $context = createHttpContext();

  while ($retries < FETCH_MAX_RETRIES) {
    try {
      $url = PRODUCTS_URL . '?page=' . $page . '&limit=' . FETCH_LIMIT;
      echo "Fetching page $page from: $url" . PHP_EOL;
      $response = file_get_contents($url, FALSE, $context);

      if ($response === FALSE) {
        throw new Exception("Failed to fetch URL: $url");
      }

      $data = json_decode($response, TRUE, 512, JSON_THROW_ON_ERROR);
      return $data['products'] ?? [];
    }
    catch (Exception $e) {
      $retries++;
      echo "Attempt $retries failed for page $page: " . $e->getMessage() . PHP_EOL;
      if ($retries >= FETCH_MAX_RETRIES) {
        throw new Exception("Max retries reached for page $page: " . $e->getMessage());
      }
      sleep(pow(2, $retries)); // Exponential backoff
    }
  }

  return [];
}

/**
 * Recursively formats HTML elements with indentation, handling self-closing
 * tags.
 *
 * @param DOMNode $node
 * @param int $level
 *
 * @return string
 */
function formatNode(DOMNode $node, int $level = 0): string {
  $output = "";
  $indent = str_repeat("  ", $level); // 2 spaces per level of indentation
  $selfClosingTags = [
    'img',
    'br',
    'meta',
    'input',
    'link',
    'hr',
  ]; // Define common self-closing tags

  foreach ($node->childNodes as $child) {
    if ($child->nodeType === XML_TEXT_NODE) {
      $text = trim($child->textContent);
      if ($text !== '') {
        $output .= $indent . htmlspecialchars($text) . PHP_EOL;
      }
    }
    elseif ($child->nodeType === XML_ELEMENT_NODE) {
      $output .= $indent . "<" . $child->nodeName;

      // Add attributes
      foreach ($child->attributes as $attr) {
        $output .= " " . $attr->nodeName . '="' . htmlspecialchars($attr->nodeValue) . '"';
      }

      // Check if the element is self-closing
      if (in_array($child->nodeName, $selfClosingTags)) {
        $output .= " />" . PHP_EOL; // Self-close the tag
      }
      else {
        // Close the opening tag and process children if any
        $output .= ">";
        if ($child->hasChildNodes()) {
          $output .= PHP_EOL . formatNode($child, $level + 1) . $indent . "</" . $child->nodeName . ">" . PHP_EOL;
        }
        else {
          $output .= "</" . $child->nodeName . ">" . PHP_EOL;
        }
      }
    }
  }

  return $output;
}

/**
 * Generate footer row with counts
 *
 * @param $label
 * @param $data
 *
 * @return string
 */
function generateFooterRow($label, $data): string {
  $rowHtml = "<tr><td>$label</td>";
  foreach ($data as $value) {
    $rowHtml .= '<td>' . htmlspecialchars($value) . '</td>';
  }
  $rowHtml .= '</tr>';
  return $rowHtml;
}

/**
 * Generate HTML for the complete table
 *
 * @param $enrichedProducts
 * @param $allIngredients
 * @param $ingredientTotals
 * @param $productImages
 *
 * @return string
 */
/**
 * Generate HTML for the complete table with an archive link
 *
 * @param $enrichedProducts
 * @param $allIngredients
 * @param $ingredientTotals
 * @param $productImages
 *
 * @return string
 */
function generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages): string {
  $generationDate = date('F j, Y');
  $recipeCount = count($enrichedProducts);
  $ingredientCount = count($allIngredients);
  $totalTagged = 146; // From extraction analysis
  $captureRate = round(($recipeCount / $totalTagged) * 100, 1);

  // Start HTML
  $html = '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="UTF-8">';
  $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
  $html .= '<title>Birmingham Ink Recipes - ' . $generationDate . '</title>';
  $html .= '<link rel="stylesheet" href="../template/styles.css">';
  $html .= '</head><body>';

  // Header with gradient, stats, and actions
  $html .= '<header>';
  $html .= '<div class="header-content">';
  $html .= '<div class="header-top">';
  $html .= '<div><h1>Birmingham Ink Recipes</h1><div class="header-date">Updated ' . $generationDate . '</div></div>';
  $html .= '<div class="header-actions">';
  $html .= '<div class="theme-toggle" id="themeToggle"></div>';
  $html .= '<a href="index.html" class="btn btn-icon" title="Archive">🗂️</a>';
  $html .= '</div></div>';

  // Stats bar
  $html .= '<div class="stats-bar">';
  $html .= '<div class="stat"><div><div class="stat-value">' . $recipeCount . '</div><div class="stat-label">Recipes</div></div></div>';
  $html .= '<div class="stat"><div><div class="stat-value">' . $ingredientCount . '</div><div class="stat-label">Ingredients</div></div></div>';
  $html .= '<div class="stat"><div><div class="stat-value">' . $captureRate . '%</div><div class="stat-label">Captured</div></div></div>';
  $html .= '</div></div></header>';

  // Main content
  $html .= '<main>';

  // Controls
  $html .= '<div class="controls"><div class="controls-grid">';
  $html .= '<div class="search-wrapper"><span class="search-icon">🔍</span>';
  $html .= '<input type="text" class="search-input" placeholder="Search recipes by name or ingredient..." id="searchInput"></div>';
  $html .= '<div class="view-toggle">';
  $html .= '<button class="view-btn" data-view="cards">Cards</button>';
  $html .= '<button class="view-btn active" data-view="table">Table</button>';
  $html .= '</div></div></div>';

  // Filter section with ingredient pills (show top 12 ingredients)
  $html .= '<div class="filter-section"><div class="filter-title">Filter by Ingredient</div><div class="filter-pills">';
  $topIngredients = array_slice($allIngredients, 0, 12);
  foreach ($topIngredients as $ingredient) {
    $html .= '<div class="filter-pill">' . htmlspecialchars($ingredient) . '</div>';
  }
  if (count($allIngredients) > 12) {
    $remaining = count($allIngredients) - 12;
    $html .= '<div class="filter-pill">+ ' . $remaining . ' more</div>';
  }
  $html .= '</div></div>';

  // Card View
  $html .= '<div id="cardView" class="card-grid hidden">';
  foreach ($enrichedProducts as $product) {
    $html .= generateRecipeCard($product, $productImages);
  }
  $html .= '</div>';

  // Table View
  $html .= '<div id="tableView" class="table-wrapper"><div class="table-scroll"><table>';
  $html .= generateTableHeader($allIngredients, $productImages);
  $html .= '<tbody>';
  foreach ($enrichedProducts as $product) {
    $html .= generateTableRow($product, $allIngredients, $productImages);
  }
  $html .= '</tbody>';
  $html .= generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);
  $html .= '</table></div></div>';

  $html .= '</main>';

  // Script
  $html .= '<script src="../template/script.js"></script>';
  $html .= '</body></html>';

  return $html;
}

/**
 * Generate a recipe card for card view
 *
 * @param $product
 * @param $productImages
 * @return string
 */
function generateRecipeCard($product, $productImages): string {
  $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
  $localImagePath = isset($productImages[$product['title']]) ? '../images/' . basename($productImages[$product['title']]) : '';

  $html = '<div class="recipe-card">';

  // Card image
  $imageFullPath = isset($productImages[$product['title']]) ? __DIR__ . '/../output/images/' . basename($productImages[$product['title']]) : '';
  if ($imageFullPath && file_exists($imageFullPath)) {
    $html .= '<img class="card-image" src="' . htmlspecialchars($localImagePath) . '" alt="' . htmlspecialchars($product['title']) . '">';
  } else {
    $html .= '<div class="card-image"></div>';
  }

  // Card content
  $html .= '<div class="card-content">';
  $html .= '<h3 class="card-title"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></h3>';

  // Ingredient badges
  if (!empty($product['recipe_components'])) {
    $html .= '<div class="ingredients-list">';
    foreach ($product['recipe_components'] as $ingredient => $quantity) {
      $qtyClass = getQuantityClass($quantity);
      $html .= '<span class="ingredient-badge ' . $qtyClass . '">';
      $html .= '<span>' . $quantity . '</span>';
      $html .= '<span>' . htmlspecialchars($ingredient) . '</span>';
      $html .= '</span>';
    }
    $html .= '</div>';
  }

  $html .= '</div></div>';

  return $html;
}

/**
 * Generate a table row
 *
 * @param $product
 * @param $allIngredients
 * @param $productImages
 * @return string
 */
function generateTableRow($product, $allIngredients, $productImages): string {
  $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
  $localImagePath = isset($productImages[$product['title']]) ? '../images/' . basename($productImages[$product['title']]) : '';

  $html = '<tr><td><div class="product-cell">';

  // Product image
  $imageFullPath = isset($productImages[$product['title']]) ? __DIR__ . '/../output/images/' . basename($productImages[$product['title']]) : '';
  if ($imageFullPath && file_exists($imageFullPath)) {
    $html .= '<img class="product-img" src="' . htmlspecialchars($localImagePath) . '" alt="' . htmlspecialchars($product['title']) . '">';
  }

  // Product name
  $html .= '<div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';
  $html .= '</div></td>';

  // Ingredient quantities
  foreach ($allIngredients as $ingredient) {
    $quantity = $product['recipe_components'][$ingredient] ?? '';
    $html .= '<td class="qty-cell">' . $quantity . '</td>';
  }

  $html .= '</tr>';

  return $html;
}

/**
 * Get CSS class for quantity badge based on value
 *
 * @param int $quantity
 * @return string
 */
function getQuantityClass(int $quantity): string {
  if ($quantity <= 10) {
    return 'qty-low';
  } elseif ($quantity <= 50) {
    return 'qty-medium';
  } else {
    return 'qty-high';
  }
}

/**
 * Generate HTML footer for Recipe Count and Quantity Count
 *
 * @param $allIngredients
 * @param $enrichedProducts
 * @param $ingredientTotals
 *
 * @return string
 */
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

/**
 * Generate HTML header for the table
 *
 * @param $allIngredients
 * @param $productImages
 *
 * @return string
 */
function generateTableHeader($allIngredients, $productImages): string {
  $headerHtml = '<thead><tr><th class="sortable">Product</th>';
  foreach ($allIngredients as $ingredient) {
    $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
    $headerHtml .= '<th class="sortable"><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient);

    if (isset($productImages[$ingredient])) {
      // Construct relative path for the image
      $localImagePath = '../images' . '/' . basename($productImages[$ingredient]);
      $headerHtml .= '<br><img src="' . htmlspecialchars($localImagePath) . '" alt="' . htmlspecialchars($ingredient) . '" class="ingredient-img">';
    }

    $headerHtml .= '</a></th>';
  }
  $headerHtml .= '</tr></thead>';
  return $headerHtml;
}

/**
 * Load and validate JSON data
 *
 * @param $filePath
 *
 * @return array
 * @throws \Exception
 */
function loadProducts($filePath): array {
  checkInputFile($filePath);

  $jsonData = file_get_contents($filePath);
  $products = json_decode($jsonData, TRUE);

  if (!is_array($products)) {
    throw new Exception("Invalid or missing JSON data in $filePath");
  }

  return $products;
}

/**
 * Prettify HTML by converting it into properly indented format.
 *
 * @param string $html
 *
 * @return string
 */
function prettifyHTML(string $html): string {
  $dom = new DOMDocument('1.0', 'UTF-8');
  @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  return formatNode($dom->documentElement);
}

/**
 * @param $products
 *
 * @return array
 */
function processProducts($products): array {
  $enrichedProducts = [];
  $ingredientTotals = [];
  $productImages = [];

  foreach ($products as $product) {
    // Capture main image for the product
    if (!empty($product['images'][0]['src'])) {
      $imageUrl = $product['images'][0]['src'];
      $cleanedImageName = cleanImageName($imageUrl); // Clean the image name
      $imagePath = IMAGE_DIR . '/' . $cleanedImageName;

      // Download the image if it doesn't already exist
      downloadImageIfNeeded($imageUrl, $imagePath);

      // Map product title to the downloaded image path
      $productImages[$product['title']] = $imagePath;
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

/**
 * Updates relative paths in the index file to make them root-relative.
 *
 * @param string $indexFile Path to the index file to modify.
 * @return void
 */
function updatePathsInIndex(string $indexFile): void {
  // Read the current contents of index.html
  $content = file_get_contents($indexFile);

  // Replace '../images' with 'images' and '../template' with 'template'
  $updatedContent = str_replace(['../images', '../template'], ['images', 'template'], $content);

  // Replace the archive link href from 'index.html' to 'archive/'
  $updatedContent = str_replace(
    '<a href="index.html" class="btn btn-icon" title="Archive">',
    '<a href="archive/" class="btn btn-icon" title="Archive">',
    $updatedContent
  );

  // Write the updated content back to index.html
  file_put_contents($indexFile, $updatedContent);
}
