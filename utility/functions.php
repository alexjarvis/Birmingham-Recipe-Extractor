<?php

const RECIPE_FETCH_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
const RECIPE_FETCH_TIMEOUT_SECONDS = 30;
const RECIPE_FETCH_CONNECT_TIMEOUT_SECONDS = 10;
const RECIPE_FETCH_MAX_ATTEMPTS = 3;

/**
 * @throws \Exception
 */
function checkInputFile(string $path): void {
  if (!is_file($path)) {
    throw new Exception("Failed to load input file: " . $path);
  }
}

/**
 * Checks for the existence of output directory and creates it if necessary.
 *
 * @throws \Exception
 */
function checkOutputDir(string $dir): void {
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0777, TRUE) && !is_dir($dir)) {
      throw new Exception("Failed to create output directory: " . $dir);
    }
  }
}

/**
 * Helper function to clean the image name by removing query parameters
 */
function cleanImageName(string $imageUrl): string {
  $urlParts = parse_url($imageUrl);
  return basename($urlParts['path'] ?? $imageUrl);
}

/**
 * Correct known recipe typos.
 */
function correctTypos(string $name): string {
  $corrections = [
    'Saltwater Taffy' => 'Salt Water Taffy',
    'Sterling Siver' => 'Sterling Silver',
    'Tiger Lil' => 'Tiger Lily',
    'Teaberry Ice Crea' => 'Teaberry Ice Cream',
    'Diluent' => 'Dilution Solution',
    'Dilution' => 'Dilution Solution',
  ];

  return $corrections[$name] ?? $name;
}

/**
 * Create a reusable HTTP context for outbound requests.
 *
 * @return resource
 */
function createHttpContext()
{
  return stream_context_create([
    'http' => [
      'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
    ],
  ]);
}

const IMAGE_DOWNLOAD_TIMEOUT_SECONDS = 30;

/**
 * Helper function to download images if they don't already exist
 *
 * @param callable|null $fetcher fn(string $url): string|false  Optional override for tests.
 * @param callable|null $logger fn(string $message): void  Optional log sink. Defaults to stdout.
 */
function downloadImageIfNeeded(string $imageUrl, string $imagePath, ?callable $fetcher = null, ?callable $logger = null): void {
  if (!file_exists($imagePath)) {
    $fetcher ??= function (string $url): string|false {
      // Bound the request — without a timeout, a slow CDN can hang the run.
      return @file_get_contents($url, FALSE, stream_context_create([
        'http' => ['timeout' => IMAGE_DOWNLOAD_TIMEOUT_SECONDS],
      ]));
    };
    $logger ??= fn(string $m) => print($m . PHP_EOL);
    try {
      $imageData = $fetcher($imageUrl);
      if ($imageData === FALSE) {
        throw new Exception("Failed to download image: $imageUrl");
      }
      file_put_contents($imagePath, $imageData);
      $logger("Downloaded: $imagePath");
    }
    catch (Exception $e) {
      $logger("Error downloading image: " . $e->getMessage());
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
  return extractTableContentFromString(file_get_contents($filePath) ?: '');
}

/**
 * Extracts the HTML content of the first <table> element from an HTML string.
 *
 * @param string $html
 * @return string The HTML content of the <table> element, or empty string.
 */
function extractTableContentFromString(string $html): string {
  if ($html === '') {
    return '';
  }
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);
  $dom->loadHTML($html);
  libxml_clear_errors();
  $table = $dom->getElementsByTagName('table')->item(0);
  if ($table === null) {
    return '';
  }
  $serialized = $dom->saveHTML($table);
  return $serialized === false ? '' : $serialized;
}

/**
 * @param callable|null $fetcher fn(int $page): array  Optional override for tests.
 * @param callable|null $sleeper fn(int $seconds): void  Optional override for tests.
 * @param callable|null $logger fn(string $message): void  Optional log sink. Defaults to stdout.
 * @return array<int, array<string, mixed>>
 * @throws \Exception when a page fails after all retries
 */
function fetchAllProducts(?callable $fetcher = null, ?callable $sleeper = null, ?callable $logger = null): array {
  $logger ??= fn(string $m) => print($m . PHP_EOL);
  $allProducts = [];
  $page = 1;

  while (TRUE) {
    // A page that fails after all retries throws. Swallowing it here used to
    // return a partial list, which the merge would read as "these recipes are
    // gone" — far worse than aborting the run and retrying tomorrow.
    $products = fetchPage($page, $fetcher, $sleeper, $logger);
    if (empty($products)) {
      $logger("No more products found on page $page. Stopping.");
      break;
    }

    $productCount = count($products);
    $logger("Retrieved $productCount products from page $page");
    $allProducts = array_merge($allProducts, $products);
    $page++;
  }

  $totalProducts = count($allProducts);
  $logger("Total products fetched: $totalProducts");
  return $allProducts;
}

/**
 * Fetch a single page of products.
 *
 * @param callable|null $fetcher fn(string $url): string|false
 * @param callable|null $sleeper fn(int $seconds): void
 * @param callable|null $logger fn(string $message): void  Optional log sink. Defaults to stdout.
 *
 * @return array<int, array<string, mixed>>
 * @throws \Exception
 */
function fetchPage(int $page, ?callable $fetcher = null, ?callable $sleeper = null, ?callable $logger = null): array {
  $fetcher ??= fn(string $url): string|false => file_get_contents($url, FALSE, createHttpContext());
  $sleeper ??= 'sleep';
  $logger ??= fn(string $m) => print($m . PHP_EOL);
  $retries = 0;

  while ($retries < FETCH_MAX_RETRIES) {
    try {
      $url = PRODUCTS_URL . '?page=' . $page . '&limit=' . FETCH_LIMIT;
      $logger("Fetching page $page from: $url");
      $response = $fetcher($url);

      if ($response === FALSE) {
        throw new Exception("Failed to fetch URL: $url");
      }

      $data = json_decode($response, TRUE, 512, JSON_THROW_ON_ERROR);
      return $data['products'] ?? [];
    }
    catch (Exception $e) {
      $retries++;
      $logger("Attempt $retries failed for page $page: " . $e->getMessage());
      if ($retries >= FETCH_MAX_RETRIES) {
        throw new Exception("Max retries reached for page $page: " . $e->getMessage());
      }
      $sleeper((int) pow(2, $retries));
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
      if ($child->attributes !== null) {
        foreach ($child->attributes as $attr) {
          $output .= " " . $attr->nodeName . '="' . htmlspecialchars($attr->nodeValue ?? '') . '"';
        }
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
 * @param array<int, int|string> $data
 */
function generateFooterRow(string $label, array $data): string {
  $rowHtml = "<tr><td>$label</td>";
  foreach ($data as $value) {
    $rowHtml .= '<td>' . htmlspecialchars((string) $value) . '</td>';
  }
  $rowHtml .= '</tr>';
  return $rowHtml;
}

/**
 * Generate the <head> section and opening <body> tag.
 */
function generateDocumentHead(string $generationDate): string {
  return '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<title>Birmingham Ink Recipes - ' . $generationDate . '</title>'
    . '<link rel="stylesheet" href="../template/styles.css">'
    . '</head><body>';
}

/**
 * Generate the page header with gradient, stats bar, theme toggle, and archive link.
 */
function generatePageHeader(string $generationDate, int $recipeCount, int $ingredientCount, float $captureRate): string {
  return '<header>'
    . '<div class="header-content">'
    . '<div class="header-top">'
    . '<div><h1>Birmingham Ink Recipes</h1><div class="header-date">Updated ' . $generationDate . '</div></div>'
    . '<div class="header-actions">'
    . '<div class="theme-toggle" id="themeToggle"></div>'
    . '<a href="index.html" class="btn btn-icon" title="Archive">🗂️</a>'
    . '</div></div>'
    . '<div class="stats-bar">'
    . '<div class="stat"><div><div class="stat-value">' . $recipeCount . '</div><div class="stat-label">Recipes</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $ingredientCount . '</div><div class="stat-label">Ingredients</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $captureRate . '%</div><div class="stat-label">Captured</div></div></div>'
    . '</div></div></header>';
}

/**
 * Generate the search input + view-toggle controls.
 */
function generateSearchControls(): string {
  return '<div class="controls"><div class="controls-grid">'
    . '<div class="search-wrapper"><span class="search-icon">🔍</span>'
    . '<input type="text" class="search-input" placeholder="Search recipes by name or ingredient..." id="searchInput"></div>'
    . '<div class="view-toggle">'
    . '<button class="view-btn" data-view="cards">Cards</button>'
    . '<button class="view-btn active" data-view="table">Table</button>'
    . '</div></div></div>';
}

/**
 * Generate the filter pills section (top 12 ingredients + remainder count).
 *
 * @param array<int, string> $allIngredients
 */
function generateFilterPills(array $allIngredients): string {
  $html = '<div class="filter-section"><div class="filter-title">Filter by Ingredient</div><div class="filter-pills">';
  $topIngredients = array_slice($allIngredients, 0, 12);
  foreach ($topIngredients as $ingredient) {
    $html .= '<div class="filter-pill">' . htmlspecialchars($ingredient) . '</div>';
  }
  if (count($allIngredients) > 12) {
    $remaining = count($allIngredients) - 12;
    $html .= '<div class="filter-pill">+ ' . $remaining . ' more</div>';
  }
  $html .= '</div></div>';
  return $html;
}

/**
 * Generate the card-view container with one recipe card per product.
 *
 * @param array<int, array<string, mixed>> $enrichedProducts
 * @param array<string, string> $productImages
 */
function generateCardView(array $enrichedProducts, array $productImages): string {
  $html = '<div id="cardView" class="card-grid hidden">';
  foreach ($enrichedProducts as $product) {
    $html .= generateRecipeCard($product, $productImages);
  }
  $html .= '</div>';
  return $html;
}

/**
 * Generate the table-view container with header, body rows, and footer.
 *
 * @param array<int, array<string, mixed>> $enrichedProducts
 * @param array<int, string> $allIngredients
 * @param array<string, int> $ingredientTotals
 * @param array<string, string> $productImages
 */
function generateTableView(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages): string {
  $html = '<div id="tableView" class="table-wrapper"><div class="table-scroll"><table>';
  $html .= generateTableHeader($allIngredients, $productImages);
  $html .= '<tbody>';
  foreach ($enrichedProducts as $product) {
    $html .= generateTableRow($product, $allIngredients, $productImages);
  }
  $html .= '</tbody>';
  $html .= generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);
  $html .= '</table></div></div>';
  return $html;
}

/**
 * Generate HTML for the complete recipe page.
 *
 * @param array<int, array<string, mixed>> $enrichedProducts
 * @param array<int, string> $allIngredients
 * @param array<string, int> $ingredientTotals
 * @param array<string, string> $productImages
 * @param int|null $totalTagged Number of products that *should* have a recipe (denominator for the capture-rate stat). Defaults to count($enrichedProducts), i.e. assumes 100% capture when not specified.
 */
function generateHTML(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages, ?int $totalTagged = null): string {
  $generationDate = date('F j, Y');
  $recipeCount = count($enrichedProducts);
  $ingredientCount = count($allIngredients);
  $totalTagged ??= $recipeCount;
  $captureRate = $totalTagged > 0 ? round(($recipeCount / $totalTagged) * 100, 1) : 0;

  return generateDocumentHead($generationDate)
    . generatePageHeader($generationDate, $recipeCount, $ingredientCount, $captureRate)
    . '<main>'
    . generateSearchControls()
    . generateFilterPills($allIngredients)
    . generateCardView($enrichedProducts, $productImages)
    . generateTableView($enrichedProducts, $allIngredients, $ingredientTotals, $productImages)
    . '</main>'
    . '<script src="../template/script.js"></script>'
    . '</body></html>';
}

/**
 * Generate a recipe card for card view
 *
 * @param array<string, mixed> $product
 * @param array<string, string> $productImages
 */
function generateRecipeCard(array $product, array $productImages): string {
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
 * @param array<string, mixed> $product
 * @param array<int, string> $allIngredients
 * @param array<string, string> $productImages
 */
function generateTableRow(array $product, array $allIngredients, array $productImages): string {
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
 * @param array<int, string> $allIngredients
 * @param array<int, array<string, mixed>> $enrichedProducts
 * @param array<string, int> $ingredientTotals
 */
function generateTableFooter(array $allIngredients, array $enrichedProducts, array $ingredientTotals): string {
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
 * @param array<int, string> $allIngredients
 * @param array<string, string> $productImages
 */
function generateTableHeader(array $allIngredients, array $productImages): string {
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
 * @return array<int, array<string, mixed>>
 * @throws \Exception
 */
function loadProducts(string $filePath): array {
  checkInputFile($filePath);

  $jsonData = file_get_contents($filePath);
  if ($jsonData === FALSE) {
    throw new Exception("Failed to read $filePath");
  }
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
  // mb_convert_encoding with 'HTML-ENTITIES' is deprecated in PHP 8.2+;
  // mb_encode_numericentity is the modern equivalent — converts non-ASCII
  // characters to numeric HTML entities so DOMDocument parses them correctly.
  @$dom->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  return $dom->documentElement === null ? '' : formatNode($dom->documentElement);
}

/**
 * @param array<int, array<string, mixed>> $products
 * @param callable|null $imageHandler fn(string $imageUrl): string  Returns the local image path. Default downloads to IMAGE_DIR.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: array<string, int>, 2: array<string, string>}
 */
function processProducts(array $products, ?callable $imageHandler = null): array {
  $imageHandler ??= function (string $imageUrl): string {
    $imagePath = IMAGE_DIR . '/' . cleanImageName($imageUrl);
    downloadImageIfNeeded($imageUrl, $imagePath);
    return $imagePath;
  };

  $enrichedProducts = [];
  $ingredientTotals = [];
  $productImages = [];

  foreach ($products as $product) {
    if (!empty($product['images'][0]['src'])) {
      $productImages[$product['title']] = $imageHandler($product['images'][0]['src']);
    }

    if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
      $enrichedProducts[] = $product;
      foreach ($product['recipe_components'] as $ingredient => $quantity) {
        $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
      }
    }
  }

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
  if ($content === FALSE) {
    throw new Exception("Failed to read $indexFile");
  }

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

/**
 * Build the curl_setopt options array for a recipe-page fetch. Pure function
 * so the configuration can be unit-tested without making real HTTP calls.
 *
 * @param string $url
 * @return array<int, mixed>
 */
function recipeFetchCurlOptions(string $url): array {
  return [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_USERAGENT => RECIPE_FETCH_USER_AGENT,
    CURLOPT_FOLLOWLOCATION => TRUE,
    CURLOPT_TIMEOUT => RECIPE_FETCH_TIMEOUT_SECONDS,
    CURLOPT_CONNECTTIMEOUT => RECIPE_FETCH_CONNECT_TIMEOUT_SECONDS,
  ];
}

/**
 * Default HTTP fetcher backed by curl. Returns ['status' => int, 'body' => string|false].
 *
 * @param string $url
 * @return array{status:int, body:string|false}
 */
function curlFetchProductPage(string $url): array {
  $ch = curl_init();
  curl_setopt_array($ch, recipeFetchCurlOptions($url));
  $body = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // CURLOPT_RETURNTRANSFER=true means curl_exec returns string|false, but
  // PHPStan's stub keeps the broader string|bool — coerce stray true to false.
  return ['status' => $status, 'body' => is_string($body) ? $body : false];
}

/**
 * Fetch a product page with retry-and-backoff. Returns the body on HTTP 200,
 * or null if all attempts fail. The fetcher and sleeper are injectable so the
 * retry behaviour can be unit-tested without real HTTP or wall-clock waits.
 *
 * @param string $url
 * @param int $maxAttempts
 * @param callable|null $fetcher fn(string $url): array{status:int, body:string|false}
 * @param callable|null $sleeper fn(int $seconds): void
 * @return string|null
 */
function fetchProductPage(string $url, int $maxAttempts = RECIPE_FETCH_MAX_ATTEMPTS, ?callable $fetcher = null, ?callable $sleeper = null): ?string {
  $fetcher ??= 'curlFetchProductPage';
  $sleeper ??= 'sleep';
  for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $result = $fetcher($url);
    $status = $result['status'] ?? 0;
    $body = $result['body'] ?? false;
    if ($status === 200 && is_string($body)) {
      return $body;
    }
    if ($attempt < $maxAttempts) {
      $sleeper((int) pow(2, $attempt));
    }
  }
  return null;
}

/**
 * Extract the inner HTML of the first .metafield-rich_text_field div on a
 * product page. Returns an empty string if the element is absent.
 *
 * @param string $pageHtml
 * @return string
 */
function extractRecipeHtmlFromPage(string $pageHtml): string {
  $dom = new DOMDocument();
  libxml_use_internal_errors(TRUE);
  $dom->loadHTML($pageHtml);
  libxml_clear_errors();
  $xpath = new DOMXPath($dom);
  $nodes = $xpath->query("//div[contains(@class, 'metafield-rich_text_field')]");
  if ($nodes === false || $nodes->length === 0) {
    return '';
  }
  $first = $nodes->item(0);
  if (!$first instanceof DOMNode) {
    return '';
  }
  $out = '';
  foreach ($first->childNodes as $child) {
    $serialized = $dom->saveHTML($child);
    if ($serialized !== false) {
      $out .= $serialized;
    }
  }
  return $out;
}

/**
 * Parse a recipe HTML fragment into [ingredient => quantity] pairs.
 * Applies typo correction and filters product-description noise.
 *
 * @param string $recipeHtml
 * @return array<string,int>
 */
function parseRecipeComponents(string $recipeHtml): array {
  $components = [];
  if ($recipeHtml === '') {
    return $components;
  }
  $recipeHtml = str_replace("\xc2\xa0", ' ', $recipeHtml);
  // Body capture excludes commas so that comma-separated ingredients within a
  // single tag (e.g. "<li>1 part X, 1 part Y</li>", a Birmingham format
  // introduced in May 2026) are split, not collapsed into one entry.
  if (preg_match_all('/(?:<strong>\s*(\d+)\s*<\/strong>\s*|\+?\s*(\d+)\s*)\s*(?:parts?\s*)?(?:<a[^>]*>)?\s*([^<\n,]+?)(?:<\/a>)?\s*(?=<\/p>|<br>|<\/li>|,|$)/i', $recipeHtml, $matches)) {
    foreach ($matches[3] as $index => $name) {
      $quantity = (int) ($matches[1][$index] ?: $matches[2][$index]);
      $name = correctTypos(trim(html_entity_decode(strip_tags($name))));
      if (strlen($name) > 0 &&
          strlen($name) < 50 &&
          !preg_match('/\b(ml|volume|approximately|provide|standard|converter|refills?)\b/i', $name) &&
          preg_match('/^[A-Z]/', $name)) {
        $components[$name] = $quantity;
      }
    }
  }
  return $components;
}
