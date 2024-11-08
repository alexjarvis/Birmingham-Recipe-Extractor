<?php

// Load the JSON data from products_enriched.json
$jsonData = file_get_contents('output/products_enriched.json');
$products = json_decode($jsonData, true);

// Check if data is valid
if ($products === null || !is_array($products)) {
    die("Invalid or missing products_enriched.json data.\n");
}

// Collect all unique ingredients to form table columns
$allIngredients = [];
$filteredProducts = [];

// Filter products with recipes and collect unique ingredients
foreach ($products as $product) {
    if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
        $filteredProducts[] = $product;
        foreach ($product['recipe_components'] as $ingredient => $quantity) {
            $allIngredients[$ingredient] = true;
        }
    }
}

// Sort ingredients alphabetically
$allIngredients = array_keys($allIngredients);
sort($allIngredients);

// Sort products alphabetically by title
usort($filteredProducts, function($a, $b) {
    return strcmp($a['title'], $b['title']);
});

// Generate the HTML table
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Product Recipe Table</title>';
$html .= '<style>table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #ddd; padding: 8px; text-align: center; } th { background-color: #f2f2f2; }</style></head><body>';
$html .= '<h1>Product Recipes</h1>';
$html .= '<table><thead><tr><th>Product</th>';

// Add column headers for each unique ingredient
foreach ($allIngredients as $ingredient) {
    $html .= '<th>' . htmlspecialchars($ingredient) . '</th>';
}
$html .= '</tr></thead><tbody>';

// Add rows for each product with a recipe
foreach ($filteredProducts as $product) {
    $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
    $html .= '<tr><td><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></td>';

    // Add cells for each ingredient; if product has quantity, add it; otherwise, leave empty
    foreach ($allIngredients as $ingredient) {
        if (isset($product['recipe_components'][$ingredient])) {
            $html .= '<td>' . htmlspecialchars($product['recipe_components'][$ingredient]) . '</td>';
        } else {
            $html .= '<td></td>';
        }
    }

    $html .= '</tr>';
}

$html .= '</tbody></table></body></html>';

// Write the HTML output to a file
$outputFile = 'output/products_table.html';
file_put_contents($outputFile, $html);

echo "HTML table written to $outputFile\n";