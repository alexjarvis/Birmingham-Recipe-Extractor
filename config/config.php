<?php

$config = [
  'OUTPUT_DIR' => __DIR__ . '/../output',
  'BIRMINGHAM_BASE_URL' => 'https://www.birminghampens.com',
  'FETCH_LIMIT' => 100,
  'FETCH_MAX_RETRIES' => 5,
];

$config['PRODUCTS_URL'] = $config['BIRMINGHAM_BASE_URL'] . '/products.json';
$config['PRODUCT_URL'] = $config['BIRMINGHAM_BASE_URL'] . '/products/';
$config['PRODUCTS_FILE'] = $config['OUTPUT_DIR'] . '/products.json';
$config['ENRICHED_PRODUCTS_FILE'] = $config['OUTPUT_DIR'] . '/products_enriched.json';
$config['TABLE_FILE'] = $config['OUTPUT_DIR'] . '/recipes.html';
