<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

try {
  checkOutputDir(PRODUCTS_DIR);

  $allProducts = fetchAllProducts();
  if ($allProducts === []) {
    throw new RuntimeException('Storefront returned zero products; refusing to treat that as an empty catalogue.');
  }
  $result = json_encode($allProducts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

  if (file_put_contents(PRODUCTS_FILE, $result) !== FALSE) {
    echo "Product data saved to " . PRODUCTS_FILE . PHP_EOL;
  }
  else {
    throw new Exception("Failed to write to " . PRODUCTS_FILE);
  }
}
catch (Exception $e) {
  echo "Error: " . $e->getMessage() . PHP_EOL;
  throw $e;
}
