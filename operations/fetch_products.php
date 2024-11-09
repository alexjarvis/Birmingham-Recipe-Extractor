<?php

require_once(__DIR__ . '/../config/config.php');
require_once(__DIR__ . '/../utility/functions.php');

try {
  checkOutputDir(PRODUCTS_DIR);

  $allProducts = fetchAllProducts();
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
}
