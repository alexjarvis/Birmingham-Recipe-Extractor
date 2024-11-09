<?php

require_once(__DIR__ . '/../utility/functions.php');

$date = date('Y-m-d');
$appRoot = realpath(__DIR__ . '/..');

define("OUTPUT_DIR", getAppRelativePath(__DIR__ . '/../output', $appRoot));
define("PRODUCTS_DIR", getAppRelativePath(__DIR__ . '/../output/products', $appRoot));
define("ARCHIVE_DIR", getAppRelativePath(__DIR__ . '/../output/archive', $appRoot));
define("TEMPLATE_DIR", getAppRelativePath(__DIR__ . '/../output/template', $appRoot));
define("IMAGE_DIR", getAppRelativePath(__DIR__ . '/../output/images', $appRoot));

define("ARCHIVE_FILE", ARCHIVE_DIR . '/' . $date . '-recipes.html');
define("PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products.json');
define("ENRICHED_PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products_enriched.json');
define("INDEX_FILE", OUTPUT_DIR . '/index.html');

const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;

const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
