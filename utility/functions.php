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
 * Correct known recipe typos and spelling variants. These are silent folds:
 * the variant never appears on the page.
 */
function correctTypos(string $name): string {
  $corrections = [
    'Saltwater Taffy' => 'Salt Water Taffy',
    'Sterling Siver' => 'Sterling Silver',
    'Tiger Lil' => 'Tiger Lily',
    'Teaberry Ice Crea' => 'Teaberry Ice Cream',
    'Diluent' => 'Dilution Solution',
    'Dilution' => 'Dilution Solution',
    // Birmingham used three spellings for the same unreleased placeholder ink.
    '(Unreleased Aomtink Element)' => '(Unreleased Element)',
    '(Unreleased Atomink Element)' => '(Unreleased Element)',
  ];

  return $corrections[$name] ?? $name;
}

/**
 * Products Birmingham renamed. Old name => current name. Unlike typo folds,
 * the page shows the former name so long-time readers can still find it.
 *
 * @return array<string, string>
 */
function ingredientRenames(): array {
  return [
    'Gunpowder' => 'Flint',
  ];
}

/**
 * The one name an ingredient goes by everywhere in the repository and on the
 * page: typo folds first, then renames.
 */
function canonicalIngredientName(string $name): string {
  $name = correctTypos($name);
  return ingredientRenames()[$name] ?? $name;
}

/**
 * Former names of a canonical ingredient, for "formerly X" labels and search.
 *
 * @return array<int, string>
 */
function formerIngredientNames(string $canonical): array {
  return array_keys(ingredientRenames(), $canonical, TRUE);
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
 * Generate the page header with stats bar, theme toggle, and changes link.
 */
function generatePageHeader(string $checkedDate, int $recipeCount, int $ingredientCount, int $listedCount): string {
  return '<header>'
    . '<div class="header-content">'
    . '<div class="header-top">'
    . '<div><h1>Birmingham Ink Recipes</h1><div class="header-date">Last checked ' . $checkedDate . '</div></div>'
    . '<div class="header-actions">'
    . '<div class="theme-toggle" id="themeToggle"></div>'
    . '<a href="index.html" class="btn btn-icon" title="Changes">🗂️</a>'
    . '</div></div>'
    . '<div class="stats-bar">'
    . '<div class="stat"><div><div class="stat-value">' . $recipeCount . '</div><div class="stat-label">Recipes</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $ingredientCount . '</div><div class="stat-label">Ingredients</div></div></div>'
    . '<div class="stat"><div><div class="stat-value">' . $listedCount . '</div><div class="stat-label">On site now</div></div></div>'
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
 * Generate the filter pills section, one pill per ingredient.
 *
 * @param array<int, string> $allIngredients
 */
function generateFilterPills(array $allIngredients): string {
  $html = '<div class="filter-section"><div class="filter-title">Filter by Ingredient</div><div class="filter-pills">';
  foreach ($allIngredients as $ingredient) {
    $html .= '<div class="filter-pill" data-name="' . htmlspecialchars($ingredient) . '">' . htmlspecialchars($ingredient) . '</div>';
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
 * @param array<string, string> $ingredientHandles  Ingredient name => storefront handle, when known.
 */
function generateTableView(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages, array $ingredientHandles = []): string {
  $html = '<div id="tableView" class="table-wrapper"><div class="table-scroll"><table>';
  $html .= generateTableHeader($allIngredients, $productImages, $ingredientHandles);
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
 * @param array<int, array<string, mixed>> $enrichedProducts  Page recipes (see repositoryRecipesForPage()).
 * @param array<int, string> $allIngredients
 * @param array<string, int> $ingredientTotals
 * @param array<string, string> $productImages
 * @param string|null $checkedDate  Human date of the scan; defaults to today.
 * @param array<string, string> $ingredientHandles  Ingredient name => storefront handle, when known.
 */
function generateHTML(array $enrichedProducts, array $allIngredients, array $ingredientTotals, array $productImages, ?string $checkedDate = NULL, array $ingredientHandles = []): string {
  $checkedDate ??= date('F j, Y');
  $recipeCount = count($enrichedProducts);
  $ingredientCount = count($allIngredients);
  $listedCount = countListed($enrichedProducts);

  return generateDocumentHead($checkedDate)
    . generatePageHeader($checkedDate, $recipeCount, $ingredientCount, $listedCount)
    . '<main>'
    . generateSearchControls()
    . generateFilterPills($allIngredients)
    . generateCardView($enrichedProducts, $productImages)
    . generateTableView($enrichedProducts, $allIngredients, $ingredientTotals, $productImages, $ingredientHandles)
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

  $unlistedOn = $product['unlisted_on'] ?? NULL;
  $html = '<div class="recipe-card' . ($unlistedOn !== NULL ? ' unlisted' : '') . '">';

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
  $html .= formatUnlistedBadge($unlistedOn);

  // Ingredient badges
  if (!empty($product['recipe_components'])) {
    $html .= '<div class="ingredients-list">';
    foreach ($product['recipe_components'] as $ingredient => $quantity) {
      $qtyClass = getQuantityClass($quantity);
      $former = formerIngredientNames((string) $ingredient);
      $html .= '<span class="ingredient-badge ' . $qtyClass . '" data-name="' . htmlspecialchars((string) $ingredient) . '"'
        . ($former !== [] ? ' data-former="' . htmlspecialchars(implode(', ', $former)) . '"' : '') . '>';
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

  $unlistedOn = $product['unlisted_on'] ?? NULL;
  $html = ($unlistedOn !== NULL ? '<tr class="unlisted">' : '<tr>') . '<td><div class="product-cell">';

  // Product image
  $imageFullPath = isset($productImages[$product['title']]) ? __DIR__ . '/../output/images/' . basename($productImages[$product['title']]) : '';
  if ($imageFullPath && file_exists($imageFullPath)) {
    $html .= '<img class="product-img" src="' . htmlspecialchars($localImagePath) . '" alt="' . htmlspecialchars($product['title']) . '">';
  }

  // Product name
  $html .= '<div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';
  $html .= formatUnlistedBadge($unlistedOn);
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
 * Render a YYYY-MM-DD string with a date() format, falling back to the input
 * when it does not parse.
 */
function formatHumanDate(string $ymd, string $format = 'M j, Y'): string {
  $ts = strtotime($ymd);
  return $ts === FALSE ? $ymd : date($format, $ts);
}

/**
 * Badge shown on recipes the storefront no longer lists. Empty string when listed.
 */
function formatUnlistedBadge(?string $unlistedOn): string {
  if ($unlistedOn === NULL) {
    return '';
  }
  $human = formatHumanDate($unlistedOn);
  return '<span class="unlisted-badge" title="Last seen on birminghampens.com before ' . htmlspecialchars($unlistedOn) . '">'
    . 'Not listed since ' . htmlspecialchars($human) . '</span>';
}

/**
 * Adapt the repository to the product shape the page generators consume.
 *
 * @param array<string, mixed> $repository
 * @return array<int, array{handle: string, title: string, recipe_components: array<string, int>, unlisted_on: ?string, image: ?string}>
 */
function repositoryRecipesForPage(array $repository): array {
  $recipes = [];
  /** @var array<string, array<string, mixed>> $entries */
  $entries = $repository['recipes'] ?? [];
  foreach ($entries as $handle => $recipe) {
    /** @var array<string, int> $components */
    $components = $recipe['components'] ?? [];
    $recipes[] = [
      'handle' => (string) ($recipe['handle'] ?? $handle),
      'title' => (string) ($recipe['title'] ?? $handle),
      'recipe_components' => $components,
      'unlisted_on' => isset($recipe['unlisted_on']) ? (string) $recipe['unlisted_on'] : NULL,
      'image' => isset($recipe['image']) ? (string) $recipe['image'] : NULL,
    ];
  }
  usort($recipes, fn(array $a, array $b) => strcmp($a['title'], $b['title']));
  return $recipes;
}

/**
 * @param array<int, array<string, mixed>> $recipes
 * @return array<string, int>
 */
function ingredientTotals(array $recipes): array {
  $totals = [];
  foreach ($recipes as $recipe) {
    foreach ($recipe['recipe_components'] ?? [] as $ingredient => $quantity) {
      $totals[$ingredient] = ($totals[$ingredient] ?? 0) + (int) $quantity;
    }
  }
  return $totals;
}

/**
 * @param array<int, array<string, mixed>> $recipes
 */
function countListed(array $recipes): int {
  return count(array_filter($recipes, fn(array $r) => ($r['unlisted_on'] ?? NULL) === NULL));
}

/**
 * Title => absolute image path for repository recipes that carry an image name.
 *
 * @param array<int, array<string, mixed>> $recipes
 * @return array<string, string>
 */
function repositoryImages(array $recipes, string $imageDir): array {
  $images = [];
  foreach ($recipes as $recipe) {
    $image = $recipe['image'] ?? NULL;
    if (is_string($image) && $image !== '') {
      $images[(string) $recipe['title']] = rtrim($imageDir, '/') . '/' . $image;
    }
  }
  return $images;
}

/**
 * Ingredient name => absolute image path, from the repository's ingredient section.
 *
 * @param array<string, mixed> $repository
 * @return array<string, string>
 */
function repositoryIngredientImages(array $repository, string $imageDir): array {
  $images = [];
  /** @var array<string, array<string, mixed>> $entries */
  $entries = $repository['ingredients'] ?? [];
  foreach ($entries as $name => $meta) {
    $image = $meta['image'] ?? NULL;
    if (is_string($image) && $image !== '') {
      $images[(string) $name] = rtrim($imageDir, '/') . '/' . $image;
    }
  }
  return $images;
}

/**
 * Ingredient name => storefront handle, from the repository's ingredient section.
 *
 * @param array<string, mixed> $repository
 * @return array<string, string>
 */
function repositoryIngredientHandles(array $repository): array {
  $handles = [];
  /** @var array<string, array<string, mixed>> $entries */
  $entries = $repository['ingredients'] ?? [];
  foreach ($entries as $name => $meta) {
    $handle = $meta['handle'] ?? NULL;
    if (is_string($handle) && $handle !== '') {
      $handles[(string) $name] = $handle;
    }
  }
  return $handles;
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
 * @param array<string, string> $ingredientHandles  Ingredient name => storefront handle; falls back to a slug of the name.
 */
function generateTableHeader(array $allIngredients, array $productImages, array $ingredientHandles = []): string {
  $headerHtml = '<thead><tr><th class="sortable">Product</th>';
  foreach ($allIngredients as $ingredient) {
    $handle = $ingredientHandles[$ingredient] ?? strtolower(str_replace(' ', '-', $ingredient));
    $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode($handle);
    $headerHtml .= '<th class="sortable"><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient);
    $former = formerIngredientNames($ingredient);
    if ($former !== []) {
      $headerHtml .= '<span class="former-name">formerly ' . htmlspecialchars(implode(', ', $former)) . '</span>';
    }

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
    '<a href="index.html" class="btn btn-icon" title="Changes">',
    '<a href="archive/" class="btn btn-icon" title="Changes">',
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
      $name = canonicalIngredientName(trim(html_entity_decode(strip_tags($name))));
      if (strlen($name) > 0 &&
          strlen($name) < 50 &&
          !preg_match('/\b(ml|volume|approximately|provide|standard|converter|refills?)\b/i', $name) &&
          preg_match('/^[A-Z(]/', $name)) {
        // Two source names can fold to one canonical name; keep the larger part count.
        $components[$name] = max($components[$name] ?? 0, $quantity);
      }
    }
  }
  return $components;
}

/**
 * Human lines describing how a formula changed. Only differing ingredients.
 *
 * @param array<string, int> $from
 * @param array<string, int> $to
 * @return array<int, string>
 */
function describeFormulaChange(array $from, array $to): array {
  $names = array_unique(array_merge(array_keys($from), array_keys($to)));
  sort($names, SORT_STRING);
  $lines = [];
  foreach ($names as $name) {
    $before = $from[$name] ?? NULL;
    $after = $to[$name] ?? NULL;
    if ($before === $after) {
      continue;
    }
    if ($before === NULL) {
      $lines[] = "$name: added ($after)";
    }
    elseif ($after === NULL) {
      $lines[] = "$name: removed";
    }
    else {
      $lines[] = "$name: $before → $after";
    }
  }
  return $lines;
}

/**
 * Render the Changes page: change-log events newest first, then the frozen
 * legacy snapshot pages.
 *
 * @param array<int, array<string, mixed>> $events  Chronological change-log events.
 * @param array<int, string> $snapshotFiles  Basenames of legacy *-recipes.html files.
 */
function generateChangesPage(array $events, array $snapshotFiles, string $generationDate): string {
  $html = '<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    . '<title>Birmingham Ink Recipes - Changes</title>'
    . '<link rel="stylesheet" href="../template/styles.css">'
    . '</head><body>'
    . '<header><div class="header-content"><div class="header-top">'
    . '<div><h1>Recipe Changes</h1><div class="header-date">Generated ' . htmlspecialchars($generationDate) . '</div></div>'
    . '<div class="header-actions"><div class="theme-toggle" id="themeToggle"></div>'
    . '<a href="../" class="btn btn-icon" title="Recipes">🏠</a></div>'
    . '</div></div></header><main class="changes">';

  if ($events === []) {
    $html .= '<p class="changes-empty">No changes recorded yet.</p>';
  }
  else {
    $byDate = [];
    foreach ($events as $event) {
      $byDate[(string) $event['date']][] = $event;
    }
    krsort($byDate, SORT_STRING);
    foreach ($byDate as $date => $dayEvents) {
      $html .= '<section class="change-day"><h2>' . htmlspecialchars(formatHumanDate((string) $date)) . '</h2><ul class="change-list">';
      foreach ($dayEvents as $event) {
        $type = (string) $event['event'];
        $url = PRODUCT_URL . urlencode((string) $event['handle']);
        $html .= '<li class="change-item"><span class="change-type change-' . htmlspecialchars($type) . '">' . htmlspecialchars(ucfirst($type)) . '</span> '
          . '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars((string) $event['title']) . '</a>';
        if ($type === 'changed') {
          /** @var array<string, int> $from */
          $from = $event['from'] ?? [];
          /** @var array<string, int> $to */
          $to = $event['to'] ?? [];
          $html .= '<ul class="change-diff">';
          foreach (describeFormulaChange($from, $to) as $line) {
            $html .= '<li>' . htmlspecialchars($line) . '</li>';
          }
          $html .= '</ul>';
        }
        $html .= '</li>';
      }
      $html .= '</ul></section>';
    }
  }

  if ($snapshotFiles !== []) {
    rsort($snapshotFiles, SORT_STRING);
    $html .= '<section class="legacy"><h2>Legacy snapshots</h2>'
      . '<p>Daily captures of the storefront from before the repository model. Frozen; no new snapshots are written.</p><ul>';
    foreach ($snapshotFiles as $file) {
      if (!preg_match('/(\d{4}-\d{2}-\d{2})/', $file, $m)) {
        continue;
      }
      $html .= '<li><a href="' . htmlspecialchars($file) . '">Recipes as of ' . htmlspecialchars(formatHumanDate($m[1])) . '</a></li>';
    }
    $html .= '</ul></section>';
  }

  return $html . '</main><script src="../template/script.js"></script></body></html>';
}
