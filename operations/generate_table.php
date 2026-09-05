<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');
require_once(__DIR__ . '/../utility/recipe_repository.php');

try {
  checkInputFile(ENRICHED_PRODUCTS_FILE);
  checkOutputDir(OUTPUT_DIR);
  checkOutputDir(IMAGE_DIR);

  // The page renders the whole repository, not just what the site lists today.
  $repository = loadJsonDocument(RECIPES_FILE, emptyRepository());
  $recipes = repositoryRecipesForPage($repository);

  // Today's product list still drives image downloads: base inks used as
  // ingredients (Airline, Chimney Soot, ...) are live products with images.
  $liveProducts = loadProducts(ENRICHED_PRODUCTS_FILE);
  [, , $liveImages] = processProducts($liveProducts);
  // Precedence: live product image, then the repository's remembered image
  // (recovered from legacy snapshots for inks the storefront dropped).
  $productImages = array_merge(repositoryImages($recipes, IMAGE_DIR), repositoryIngredientImages($repository, IMAGE_DIR), $liveImages);
  $ingredientHandles = repositoryIngredientHandles($repository);

  $ingredientTotals = ingredientTotals($recipes);
  $allIngredients = array_keys($ingredientTotals);
  sort($allIngredients);

  echo "Recipes in repository: " . count($recipes) . "\n";
  echo "Currently listed: " . countListed($recipes) . "\n";
  echo "Unique ingredients: " . count($allIngredients) . "\n";

  $html = generateHTML($recipes, $allIngredients, $ingredientTotals, $productImages, formatHumanDate(SCAN_DATE, 'F j, Y'), $ingredientHandles);

  file_put_contents(INDEX_FILE, prettifyHTML($html));
  updatePathsInIndex(INDEX_FILE);
  echo "✓ Wrote " . INDEX_FILE . PHP_EOL;
}
catch (Exception $e) {
  echo 'Error: ' . $e->getMessage() . PHP_EOL;
  throw $e;
}
