<?php

$date = date('Y-m-d');

const OUTPUT_DIR = __DIR__ . '/../output';
const PRODUCTS_DIR = OUTPUT_DIR . '/products';
const ARCHIVE_DIR = OUTPUT_DIR . '/archive';
const CURRENT_DIR = OUTPUT_DIR . '/current';
$dateFileName = ARCHIVE_DIR . '/' . $date;

define("ARCHIVE_FILE", ARCHIVE_DIR . '/' . $date . '-recipes.html');
define("PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products.json');
define("ENRICHED_PRODUCTS_FILE", PRODUCTS_DIR . '/' . $date . '-products_enriched.json');

const BIRMINGHAM_BASE_URL = 'https://www.birminghampens.com';
const FETCH_LIMIT = 100;
const FETCH_MAX_RETRIES = 5;

const IMAGE_DIR = OUTPUT_DIR . '/images';
const INDEX_FILE = CURRENT_DIR . '/index.html';

const PRODUCTS_URL = BIRMINGHAM_BASE_URL . '/products.json';
const PRODUCT_URL = BIRMINGHAM_BASE_URL . '/products/';
