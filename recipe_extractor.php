<?php

// Load the HTML file
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

    $soldOutNode = $xpath->query(".//span[contains(@class, 'badge--soldout')]", $node);
    $soldOut = $soldOutNode->length > 0 ? 1 : 0;

    $onSaleNode = $xpath->query(".//div[contains(@class, 'price--on-sale')]", $node);
    $onSale = $onSaleNode->length > 0 ? 1 : 0;

    // Add product details to the array
    $products[] = [
        'Name' => $name,
        'URL' => $url,
        'Price' => $price,
        'Sold Out' => $soldOut,
        'Sale' => $onSale,
    ];
}

// Print the results
print_r($products);