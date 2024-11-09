<?php


$date = date('Y-m-d');
const OUTPUT_DIR = __DIR__ . '/../output';
const ARCHIVE_DIR = OUTPUT_DIR . '/archive';
$dateFileName = ARCHIVE_DIR . '/' . $date;
define("ARCHIVE_FILE", $dateFileName . '-recipes.html');
define("ENRICHED_PRODUCTS_FILE", "$dateFileName-products_enriched.json");
define("PRODUCTS_FILE", $dateFileName . '-products.json');
const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;
const IMAGE_DIR = OUTPUT_DIR . '/images';
const INDEX_FILE = OUTPUT_DIR . '/index.html';
const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
