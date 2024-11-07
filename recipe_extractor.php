<?php

// Function to fetch and parse HTML content from a URL
function fetchPageContent($url, $query) {
    // Initialize cURL
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Execute cURL request and get the response
    $html = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return '';
    }

    // Close cURL session
    curl_close($ch);

    // Parse the HTML and extract the recipe content
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $contentNode = $xpath->query($query);
//    $content = $contentNode->length > 0 ? trim($contentNode[0]->nodeValue) : '';

    // Use C14N to get the inner HTML with tags preserved
    $content = '';
    if ($contentNode->length > 0) {
        foreach ($contentNode[0]->childNodes as $child) {
            $content .= $dom->saveHTML($child);
        }
    }

    return $content;

    return $content;
}

// Load the main HTML file
$html = file_get_contents('Ink_Hue_Catalogue_Birmingham_Pen_Company.html');

// Use DOMDocument to parse HTML
$dom = new DOMDocument();
libxml_use_internal_errors(true); // Ignore parsing errors due to HTML5 compatibility
$dom->loadHTML($html);
libxml_clear_errors();

// Prepare XPath for selecting elements
$xpath = new DOMXPath($dom);

// Find all product items with the .card-wrapper class
$productNodes = $xpath->query("//div[contains(@class, 'card-wrapper')]");
$products = [];

foreach ($productNodes as $node) {
    // Extract the product URL
    $urlNode = $xpath->query(".//a[contains(@class, 'full-unstyled-link')]", $node);
    $url = $urlNode->length > 0 ? "https://www.birminghampens.com" . $urlNode[0]->getAttribute('href') : '';

    // Extract the product name
    $nameNode = $xpath->query(".//span[contains(@class, 'visually-hidden')]", $node);
    $name = $nameNode->length > 0 ? trim($nameNode[0]->nodeValue) : '';

    // Extract the product price
    $priceNode = $xpath->query(".//span[contains(@class, 'price')]", $node);
    $price = $priceNode->length > 0 ? trim($priceNode[0]->nodeValue) : '';

    // Check if the product is sold out
    $soldOutNode = $xpath->query(".//span[contains(@class, 'badge--soldout')]", $node);
    $soldOut = $soldOutNode->length > 0 ? 1 : 0;

    // Check if the product is on sale
    $onSaleNode = $xpath->query(".//div[contains(@class, 'price--on-sale')]", $node);
    $onSale = $onSaleNode->length > 0 ? 1 : 0;

    // Fetch additional content from the product URL
    $recipe = $url ? fetchPageContent($url, "//div[contains(@class, 'metafield-rich_text_field')]") : '';

    $product = [
        'Name' => $name,
        'URL' => $url,
        'Price' => $price,
        'Sold Out' => $soldOut,
        'Sale' => $onSale,
        'Recipe' => $recipe
    ];
    print_r($product);
    // Add product details to the array
    $products[] = $product;
}

// Print the results
print_r($products);
