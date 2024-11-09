<?php

$date = date('Y-m-d');
$appRoot = realpath(__DIR__ . '/..');

define("OUTPUT_DIR", __DIR__ . '/../output');
define("PRODUCTS_DIR", __DIR__ . '/../output/products');
define("ARCHIVE_DIR", __DIR__ . '/../output/archive');
define("TEMPLATE_DIR", 'output/template');
define("IMAGE_DIR", 'output/images');

define("ARCHIVE_FILE", ARCHIVE_DIR . '/' . $date . '-recipes.html');
define("PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products.json');
define("ENRICHED_PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products_enriched.json');
define("INDEX_FILE", OUTPUT_DIR . '/index.html');

const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;

const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
