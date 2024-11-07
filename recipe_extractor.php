<?php

// Initialize cURL with common settings
function initializeCurl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $html = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    return $html;
}

// Fetch and parse the recipe from a product URL
function fetchInkRecipe(string $url): array
{
    $html = initializeCurl($url);
    if (!$html) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $recipeNode = $xpath->query("//div[contains(@class, 'metafield-rich_text_field')]");

    if ($recipeNode->length === 0) return [];

    $fullRecipeText = $dom->saveHTML($recipeNode[0]);
    $keywords = ['Keystone', 'Everlasting', 'Twinkle', 'Delicate', 'Atomink', 'Recipe coming soon'];
    $keyword = detectKeyword($fullRecipeText, $keywords);
    if ($keyword) return [$keyword => $keyword];

    return parseRecipe($recipeNode[0]);
}

// Detect if any keyword is present in the text
function detectKeyword(string $text, array $keywords): ?string
{
    foreach ($keywords as $keyword) {
        if (stripos($text, $keyword) !== false) {
            return $keyword;
        }
    }
    return null;
}

// Parse the recipe from the DOM node
function parseRecipe(DOMNode $node): array
{
    $recipe = [];
    foreach ($node->childNodes as $child) {
        if ($child->nodeName === 'p') {
            $line = ['Name' => '', 'Quantity' => '', 'URL' => ''];

            foreach ($child->childNodes as $subChild) {
                $text = trim(str_replace("\xc2\xa0", ' ', $subChild->nodeValue)); // Replace non-breaking spaces
                if ($subChild->nodeName === '#text' && preg_match('/^(\d+)\s+Parts?\b/i', $text, $matches)) {
                    $line['Quantity'] = $matches[1];
                } elseif ($subChild->nodeName === 'a') {
                    $line['Name'] = trim($subChild->nodeValue);
                    $line['URL'] = $subChild->getAttribute('href');
                }
            }
            if ($line['Name']) $recipe[$line['Name']] = $line;
        }
    }
    return $recipe;
}

// Extract product information from a node
function parseProductNode(DOMNode $node, DOMXPath $xpath): array
{
    $urlNode = $xpath->query(".//a[contains(@class, 'full-unstyled-link')]", $node);
    $url = $urlNode->length > 0 ? "https://www.birminghampens.com" . trim($urlNode[0]->getAttribute('href')) : '';

    $nameNode = $xpath->query(".//span[contains(@class, 'visually-hidden')]", $node);
    $name = $nameNode->length > 0 ? trim($nameNode[0]->nodeValue) : '';

    $priceNode = $xpath->query(".//span[contains(@class, 'price')]", $node);
    $price = $priceNode->length > 0 ? trim($priceNode[0]->nodeValue) : '';

    $soldOutNode = $xpath->query(".//span[contains(@class, 'badge--soldout')]", $node);
    $soldOut = $soldOutNode->length > 0 ? 1 : 0;

    $onSaleNode = $xpath->query(".//div[contains(@class, 'price--on-sale')]", $node);
    $onSale = $onSaleNode->length > 0 ? 1 : 0;

    return [
        'Name' => $name,
        'URL' => $url,
        'Price' => $price,
        'Sold Out' => $soldOut,
        'Sale' => $onSale,
        'Recipe' => $url ? fetchInkRecipe($url) : '',
    ];
}

// Main script
$html = file_get_contents('Ink_Hue_Catalogue_Birmingham_Pen_Company.html');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$productNodes = $xpath->query("//div[contains(@class, 'card-wrapper')]");
$products = [];

foreach ($productNodes as $node) {
    $product = parseProductNode($node, $xpath);
    print_r($product);
    $products[] = $product;
}

print_r($products);