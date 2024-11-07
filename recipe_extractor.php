<?php

// Function to fetch and parse HTML content from a URL
function fetchInkRecipe($url, $query): array
{
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
        return [];
    }

    // Close cURL session
    curl_close($ch);

    // Parse the HTML and extract the recipe content
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $recipeNode = $xpath->query($query);

    // Keywords to check in the recipe body
    $keywords = ['Keystone', 'Everlasting', 'Twinkle', 'Delicate', 'Atomink', 'Recipe coming soon'];

    // If no recipe content is found, return an empty array
    if ($recipeNode->length === 0) {
        return [];
    }

    // Get the full recipe text for keyword detection
    $fullRecipeText = $dom->saveHTML($recipeNode[0]);

    // Check for keywords in the recipe
    foreach ($keywords as $keyword) {
        if (stripos($fullRecipeText, $keyword) !== false) {
            return [
                $keyword => $keyword,
            ];
        }
    }

    // Process each line in the recipe and format as requested
    $recipe = [];
    foreach ($recipeNode[0]->childNodes as $child) {
        if ($child->nodeName === 'p') {
            $line = [
                'Name' => '',
                'Quantity' => '',
                'URL' => ''
            ];

            // Iterate over child nodes in <p> to parse quantity and name
            foreach ($child->childNodes as $subChild) {
                if ($subChild->nodeName === '#text') {
                    // Normalize non-breaking spaces and extract quantity
                    $text = trim(str_replace("\xc2\xa0", ' ', $subChild->nodeValue));
                    if (preg_match('/^(\d+)\s+Part[s]?\b/i', $text, $matches)) {
                        $line['Quantity'] = $matches[1];
                    }
                } elseif ($subChild->nodeName === 'a') {
                    // Set the name to the anchor text and extract the URL
                    $line['Name'] = trim($subChild->nodeValue);
                    $line['URL'] = $subChild->getAttribute('href');
                }
            }

            // Use 'Name' as the key in the recipe array
            $key = $line['Name'];
            $recipe[$key] = [
                'Name' => trim($key),
                'Quantity' => trim($line['Quantity']),
                'URL' => trim($line['URL']),
            ];
        }
    }

    return $recipe;
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
    $recipe = $url ? fetchInkRecipe($url, "//div[contains(@class, 'metafield-rich_text_field')]") : '';

    $product = [
        'Name' => trim($name),
        'URL' => trim($url),
        'Price' => trim($price),
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