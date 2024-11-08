<?php

// Load and validate JSON data
function loadProducts($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("File not found: $filePath");
    }

    $jsonData = file_get_contents($filePath);
    $products = json_decode($jsonData, true);

    if ($products === null || !is_array($products)) {
        throw new Exception("Invalid or missing JSON data in $filePath");
    }

    return $products;
}

// Process products to build necessary data structures
function processProducts($products) {
    $enrichedProducts = [];
    $ingredientTotals = [];
    $productImages = [];

    foreach ($products as $product) {
        // Capture main image for the product
        if (!empty($product['images'][0]['src'])) {
            $productImages[$product['title']] = $product['images'][0]['src'];
        }

        // Collect ingredients and quantities
        if (!empty($product['recipe_components']) && is_array($product['recipe_components'])) {
            $enrichedProducts[] = $product;
            foreach ($product['recipe_components'] as $ingredient => $quantity) {
                $ingredientTotals[$ingredient] = ($ingredientTotals[$ingredient] ?? 0) + $quantity;
            }
        }
    }

    // Sort products by title
    usort($enrichedProducts, fn($a, $b) => strcmp($a['title'], $b['title']));

    return [$enrichedProducts, $ingredientTotals, $productImages];
}

// Generate HTML header for the table
function generateTableHeader($allIngredients, $productImages) {
    $headerHtml = '<thead><tr><th>Product/Ingredients</th>';
    foreach ($allIngredients as $ingredient) {
        $ingredientUrl = "https://www.birminghampens.com/products/" . urlencode(strtolower(str_replace(' ', '-', $ingredient)));
        $headerHtml .= '<th><a href="' . htmlspecialchars($ingredientUrl) . '" target="_blank">' . htmlspecialchars($ingredient);

        if (isset($productImages[$ingredient])) {
            $headerHtml .= '<img src="' . htmlspecialchars($productImages[$ingredient]) . '" alt="' . htmlspecialchars($ingredient) . '" class="ingredient-img">';
        }

        $headerHtml .= '</a></th>';
    }
    $headerHtml .= '</tr></thead>';
    return $headerHtml;
}

// Generate footer row with counts
function generateFooterRow($label, $data) {
    $rowHtml = "<tr><td>$label</td>";
    foreach ($data as $value) {
        $rowHtml .= '<td>' . htmlspecialchars($value) . '</td>';
    }
    $rowHtml .= '</tr>';
    return $rowHtml;
}

// Generate HTML footer for Recipe Count and Quantity Count
function generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals) {
    $footerHtml = '<tfoot>';

    // Recipe Count Row
    $recipeCounts = array_map(function ($ingredient) use ($enrichedProducts) {
        return count(array_filter($enrichedProducts, fn($product) => isset($product['recipe_components'][$ingredient])));
    }, $allIngredients);
    $footerHtml .= generateFooterRow("Recipe Count", $recipeCounts);

    // Quantity Count Row
    $quantityCounts = array_map(fn($ingredient) => $ingredientTotals[$ingredient] ?? 0, $allIngredients);
    $footerHtml .= generateFooterRow("Quantity Count", $quantityCounts);

    $footerHtml .= '</tfoot>';
    return $footerHtml;
}

// Generate HTML for the complete table
function generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages) {
    $generationDate = date('F j, Y');
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Birmingham Ink Recipes as of ' . $generationDate . '</title>';
    $html .= '<link rel="stylesheet" href="../template/styles.css">';
    $html .= '</head><body>';
    $html .= '<header><h1>Birmingham Ink Recipes as of ' . $generationDate . '</h1></header>';
    $html .= '<main><table>';

    // Generate table header and footer
    $html .= generateTableHeader($allIngredients, $productImages);

    // Table body with product data
    $html .= '<tbody>';
    foreach ($enrichedProducts as $product) {
        $productUrl = "https://www.birminghampens.com/products/" . urlencode($product['handle']);
        $productImage = $productImages[$product['title']] ?? 'path/to/fallback_image.jpg';

        $html .= '<tr><td><div class="product-name"><a href="' . htmlspecialchars($productUrl) . '" target="_blank">' . htmlspecialchars($product['title']) . '</a></div>';
        if ($productImage) {
            $html .= '<img src="' . htmlspecialchars($productImage) . '" alt="' . htmlspecialchars($product['title']) . '" class="product-img">';
        }
        $html .= '</td>';

        foreach ($allIngredients as $ingredient) {
            $html .= '<td>' . ($product['recipe_components'][$ingredient] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody>';

    // Add footer with counts
    $html .= generateTableFooter($allIngredients, $enrichedProducts, $ingredientTotals);
    $html .= '</table></main>';

    // Footer and script for table sorting
    $html .= '<footer><p>&copy; ' . date('Y') . ' Birmingham Pens</p></footer>';
    $html .= '<script src="../template/script.js"></script>';
    $html .= '</body></html>';
    return $html;
}

// Main execution
try {
    $products = loadProducts('output/products_enriched.json');
    [$enrichedProducts, $ingredientTotals, $productImages] = processProducts($products);

    // Gather all unique ingredients sorted alphabetically
    $allIngredients = array_keys($ingredientTotals);
    sort($allIngredients);

    // Generate and save the HTML file
    $html = generateHTML($enrichedProducts, $allIngredients, $ingredientTotals, $productImages);
    $outputFile = 'output/recipes.html';

    if (file_put_contents($outputFile, $html) !== false) {
        echo "HTML table written to $outputFile\n";
    } else {
        echo "Failed to write to $outputFile\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
}