<?php

define('BASE_URL', 'https://www.birminghampens.com/products.json');
define('LIMIT', 100); // Define the page limit as a constant
define('MAX_RETRIES', 5); // Max retry attempts for network requests
define('OUTPUT_DIR', __DIR__ . '/output');
define('OUTPUT_FILE', OUTPUT_DIR . '/products.json');

// Ensure the output directory exists
if (!file_exists(OUTPUT_DIR)) {
    mkdir(OUTPUT_DIR, 0777, true);
    echo "Directory '" . OUTPUT_DIR . "' created.\n";
}

// Function to fetch a single page of products with retries
function fetchPage($page) {
    $retries = 0;
    while ($retries < MAX_RETRIES) {
        try {
            $url = BASE_URL . '?page=' . $page . '&limit=' . LIMIT;

            // Define context with User-Agent header
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 14_7_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15"
                ]
            ]);

            $response = file_get_contents($url, false, $context);
            if ($response === FALSE) {
                throw new Exception("Failed to fetch page $page");
            }
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Failed to decode JSON on page $page");
            }
            return $data['products'] ?? [];
        } catch (Exception $e) {
            echo "Attempt " . ($retries + 1) . " failed for page $page. Retrying...\n";
            $retries++;
            if ($retries === MAX_RETRIES) {
                throw new Exception("Max retries reached for page $page: " . $e->getMessage());
            }
            sleep(1); // Optional delay between retries
        }
    }
    return []; // Return empty array if all retries fail
}

// Function to fetch all products across pages
function fetchAllProducts() {
    $allProducts = [];
    $page = 1;
    $hasMoreData = true;

    while ($hasMoreData) {
        try {
            $products = fetchPage($page);
            if (count($products) > 0) {
                $allProducts = array_merge($allProducts, $products);
                $page++;
            } else {
                $hasMoreData = false; // No more products on the next page
            }
        } catch (Exception $e) {
            echo "Failed to fetch all products: " . $e->getMessage() . "\n";
            $hasMoreData = false;
        }
    }

    return $allProducts;
}

// Fetch all products and save to file
try {
    $allProducts = fetchAllProducts();
    if (file_put_contents(OUTPUT_FILE, json_encode($allProducts, JSON_PRETTY_PRINT)) !== false) {
        echo "Product data saved to " . OUTPUT_FILE . "\n";
    } else {
        echo "Failed to write to " . OUTPUT_FILE . "\n";
    }
} catch (Exception $e) {
    echo "Error in fetching process: " . $e->getMessage() . "\n";
}