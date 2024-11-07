const fs = require('fs');

// Dynamically import node-fetch
const fetch = (...args) => import('node-fetch').then(({default: fetch}) => fetch(...args));

async function fetchAllProducts() {
    const baseUrl = 'https://www.birminghampens.com/products.json';
    let allProducts = [];
    let page = 1;
    let hasMoreData = true;

    while (hasMoreData) {
        const response = await fetch(`${baseUrl}?page=${page}&limit=50`);
        const data = await response.json();

        if (data && data.products && data.products.length > 0) {
            allProducts = allProducts.concat(data.products);
            page++;
        } else {
            hasMoreData = false;  // No more products on the next page
        }
    }

    return allProducts;
}

fetchAllProducts().then(allProducts => {
    fs.writeFileSync('products.json', JSON.stringify(allProducts, null, 2));
    console.log('Product data saved to products.json');
});