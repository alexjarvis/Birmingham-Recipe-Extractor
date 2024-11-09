<?php

$config = [
  'OUTPUT_DIR' => __DIR__ . '/../output',
  'BIRMINGHAM_BASE_URL' => 'https://www.birminghampens.com',
  'FETCH_LIMIT' => 100,
  'FETCH_MAX_RETRIES' => 5,
];

$config['ENRICHED_PRODUCTS_FILE'] = $config['OUTPUT_DIR'] . '/' . date('Y-m-d') . '-products_enriched.json';
$config['IMAGE_DIR'] = $config['OUTPUT_DIR'] . '/images';
$config['PRODUCTS_FILE'] = $config['OUTPUT_DIR'] . '/' . date('Y-m-d') . '-products.json';
$config['PRODUCTS_URL'] = $config['BIRMINGHAM_BASE_URL'] . '/products.json';
$config['PRODUCT_URL'] = $config['BIRMINGHAM_BASE_URL'] . '/products/';
$config['TABLE_FILE'] = $config['OUTPUT_DIR'] . '/' . date('Y-m-d') . '-recipes.html';
