<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../utility/functions.php';

final class FunctionsTest extends TestCase
{
    #[DataProvider('cleanImageNameProvider')]
    public function testCleanImageName(string $imageUrl, string $expected): void
    {
        $this->assertSame($expected, cleanImageName($imageUrl));
    }

    public static function cleanImageNameProvider(): array
    {
        return [
            'simple filename' => [
                'https://example.com/images/product.jpg',
                'product.jpg',
            ],
            'filename with query params' => [
                'https://example.com/images/product.jpg?v=123&width=500',
                'product.jpg',
            ],
            'nested path' => [
                'https://cdn.example.com/assets/products/2024/image.png',
                'image.png',
            ],
            'filename with spaces encoded' => [
                'https://example.com/images/my%20product.jpg',
                'my%20product.jpg',
            ],
        ];
    }

    #[DataProvider('correctTyposProvider')]
    public function testCorrectTypos(string $input, string $expected): void
    {
        $this->assertSame($expected, correctTypos($input));
    }

    public static function correctTyposProvider(): array
    {
        return [
            'Saltwater Taffy correction' => ['Saltwater Taffy', 'Salt Water Taffy'],
            'Sterling Siver correction' => ['Sterling Siver', 'Sterling Silver'],
            'Tiger Lil correction' => ['Tiger Lil', 'Tiger Lily'],
            'Teaberry Ice Crea correction' => ['Teaberry Ice Crea', 'Teaberry Ice Cream'],
            'Diluent correction' => ['Diluent', 'Dilution Solution'],
            'Dilution correction' => ['Dilution', 'Dilution Solution'],
            'no correction needed' => ['Blue Velvet', 'Blue Velvet'],
            'unknown name passes through' => ['Random Ink Name', 'Random Ink Name'],
        ];
    }

    #[DataProvider('quantityClassProvider')]
    public function testGetQuantityClass(int $quantity, string $expected): void
    {
        $this->assertSame($expected, getQuantityClass($quantity));
    }

    public static function quantityClassProvider(): array
    {
        return [
            'quantity 1 is low' => [1, 'qty-low'],
            'quantity 10 is low' => [10, 'qty-low'],
            'quantity 11 is medium' => [11, 'qty-medium'],
            'quantity 50 is medium' => [50, 'qty-medium'],
            'quantity 51 is high' => [51, 'qty-high'],
            'quantity 100 is high' => [100, 'qty-high'],
        ];
    }

    #[DataProvider('footerRowProvider')]
    public function testGenerateFooterRow(string $label, array $data, string $expectedContains): void
    {
        $result = generateFooterRow($label, $data);

        $this->assertStringContainsString("<tr><td>$label</td>", $result);
        $this->assertStringContainsString('</tr>', $result);
        foreach ($data as $value) {
            $this->assertStringContainsString("<td>$value</td>", $result);
        }
    }

    public static function footerRowProvider(): array
    {
        return [
            'simple row' => [
                'Recipe Count',
                [5, 10, 15],
                '<tr><td>Recipe Count</td>',
            ],
            'quantity row' => [
                'Quantity Count',
                [100, 200, 300],
                '<tr><td>Quantity Count</td>',
            ],
        ];
    }
}
