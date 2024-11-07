<?php

// Load JSON file containing products
$jsonData = file_get_contents('products.json');
$products = json_decode($jsonData, true);

// Check if data is valid
if ($products === null || !is_array($products)) {
    die("Invalid products.json data.\n");
}

$enrichedProducts = [];

foreach ($products as $product) {
    // Extract basic information
    $price = $product['variants'][0]['price'];
    $compareAtPrice = $product['variants'][0]['compare_at_price'];
    $available = $product['variants'][0]['available'];

    // Determine if product is on sale or sold out
    $isOnSale = $compareAtPrice > $price;
    $isSoldOut = !$available;

    // Enriched product data
    $enrichedProduct = [
        'id' => $product['id'],
        'title' => $product['title'],
        'handle' => $product['handle'],
        'price' => $price,
        'on_sale' => $isOnSale,
        'sold_out' => $isSoldOut,
        'tags' => $product['tags'],
        'images' => $product['images'],
        'vendor' => $product['vendor'],
        'product_type' => $product['product_type'],
        'variants' => $product['variants'],
        'body_html' => $product['body_html']
    ];

    // Initialize recipe variables
    $recipeHtml = '';
    $recipeComponents = [];

    // Check if "Ink Recipe" appears in body_html for special cases
    if (strpos($product['body_html'], 'Ink Recipe') !== false) {
        // Special case: Extract recipe from body_html
        $recipeHtml = $product['body_html'];
    } elseif (in_array('recipe', $product['tags'])) {
        // Standard case: Fetch the recipe from the product page if tagged as "recipe"
        $handle = $product['handle'];
        $recipeUrl = "https://www.birminghampens.com/products/{$handle}";

        // Use cURL to fetch the product page
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $recipeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html !== false) {
            // Extract recipe content from the product page
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();

            $xpath = new DOMXPath($dom);
            $recipeNode = $xpath->query("//div[contains(@class, 'metafield-rich_text_field')]");

            if ($recipeNode->length > 0) {
                foreach ($recipeNode[0]->childNodes as $child) {
                    $recipeHtml .= $dom->saveHTML($child);
                }
            }
        }
    }

    // Store the original recipe HTML
    $enrichedProduct['recipe'] = trim($recipeHtml);

    // If we have recipe HTML, parse it for components
    if ($recipeHtml) {
        // Normalize non-breaking spaces
        $recipeHtml = str_replace("\xc2\xa0", ' ', $recipeHtml);

        // Improved regex to capture parts with or without <a> tags and variations in spacing
        if (preg_match_all('/(\d+)\s+Part[s]?\s*(?:<[^>]*>)?([\w\s]+)(?=<\/a>|<\/p>|<br>|\n|$)/i', $recipeHtml, $matches)) {
            foreach ($matches[2] as $index => $name) {
                // Clean up the ingredient name
                $name = trim(html_entity_decode(strip_tags($name)));
                $quantity = (int) $matches[1][$index];  // Extract the quantity
                $recipeComponents[$name] = $quantity;  // Store in components array
            }
        }
    }

    // Assign parsed components to the enriched product
    $enrichedProduct['recipe_components'] = $recipeComponents;

    // Add enriched product to the results array
    echo $product['title'] . PHP_EOL;
    $enrichedProducts[] = $enrichedProduct;
}

// Write the enriched data to products_enriched.json
file_put_contents('products_enriched.json', json_encode($enrichedProducts, JSON_PRETTY_PRINT));

echo "Enriched data written to products_enriched.json\n";