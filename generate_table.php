<?php

// Load the JSON data from products_enriched.json
$jsonData = file_get_contents('output/products_enriched.json');
$products = json_decode($jsonData, true);

// Check if data is valid
if ($products === null || !is_array($products)) {
    die("Invalid or missing products_enriched.json data.\n");
}

$enrichedProducts = [];
$ingredientTotals = [];

// Collect all unique ingredients and filter products with recipes
foreach ($products as $product) {
    if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
        $enrichedProducts[] = $product;
        foreach ($product['recipe_components'] as $ingredient => $quantity) {
            $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
        }
    }
}

// Sort ingredients and products alphabetically
$allIngredients = array_keys($ingredientTotals);
sort($allIngredients);
usort($enrichedProducts, fn($a, $b) => strcmp($a['title'], $b['title']));

// Generate HTML5 structure and style
$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Product Recipe Table</title>';
$html .= '<style>
    body { font-family: Arial, sans-serif; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background-color: #f2f2f2; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    tr:hover { background-color: #f1f1f1; }
    tfoot { background-color: #e0e0e0; font-weight: bold; }
</style></head><body>';
$html .= '<header><h1>Product Recipes</h1></header>';
$html .= '<main><table><thead><tr><th>Product</th>';

// Column headers for each unique ingredient
foreach ($allIngredients as $ingredient) {
    $html .= '<th>' . htmlspecialchars($ingredient) . '</th>';
}
$html .= '</tr></thead><tbody>';

// Rows for each product with recipe components
foreach ($enrichedProducts as $product) {
    $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
    $html .= '<tr><td><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></td>';

    // Populate cells for ingredients with quantities, linking to ingredient pages
    foreach ($allIngredients as $ingredient) {
        if (isset($product['recipe_components'][$ingredient])) {
            $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
            $html .= '<td><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($product['recipe_components'][$ingredient]) . '</a></td>';
        } else {
            $html .= '<td></td>';
        }
    }
    $html .= '</tr>';
}

// Footer row for total counts of each ingredient
$html .= '</tbody><tfoot><tr><td>Total Count</td>';
foreach ($allIngredients as $ingredient) {
    $html .= '<td>' . ($ingredientTotals[$ingredient] ?? 0) . '</td>';
}
$html .= '</tr></tfoot></table></main>';
$html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer></body></html>';

// Write the HTML output to a file
$outputFile = 'output/products_table.html';
file_put_contents($outputFile, $html);

echo "HTML table written to $outputFile\n";