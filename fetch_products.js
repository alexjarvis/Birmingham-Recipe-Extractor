const fs = require('fs');
const path = require('path');

// Dynamically import node-fetch with error handling
let fetch;
try {
    fetch = (...args) => import('node-fetch').then(({ default: fetch }) => fetch(...args));
} catch (err) {
    console.error('Failed to load node-fetch. Make sure it is installed.', err);
    process.exit(1);
}

const BASE_URL = 'https://www.birminghampens.com/products.json';
const LIMIT = 100; // Define the page limit as a constant
const MAX_RETRIES = 5; // Max retry attempts for network requests
const OUTPUT_DIR = 'output';
const OUTPUT_FILE = path.join(OUTPUT_DIR, 'products.json');

// Ensure the output directory exists
if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
    console.log(`Directory '${OUTPUT_DIR}' created.`);
}

async function fetchPage(page) {
    let retries = 0;
    while (retries < MAX_RETRIES) {
        try {
            const response = await fetch(`${BASE_URL}?page=${page}&limit=${LIMIT}`);
            if (!response.ok) {
                throw new Error(`Failed to fetch page ${page}: ${response.statusText}`);
            }
            const data = await response.json();
            return data.products || [];
        } catch (err) {
            console.error(`Attempt ${retries + 1} failed for page ${page}. Retrying...`, err);
            retries++;
            if (retries === MAX_RETRIES) throw new Error(`Max retries reached for page ${page}`);
        }
    }
}

async function fetchAllProducts() {
    let allProducts = [];
    let page = 1;
    let hasMoreData = true;

    while (hasMoreData) {
        try {
            const products = await fetchPage(page);
            if (products.length > 0) {
                allProducts = allProducts.concat(products);
                page++;
            } else {
                hasMoreData = false; // No more products on the next page
            }
        } catch (err) {
            console.error('Failed to fetch all products:', err);
            hasMoreData = false;
        }
    }

    return allProducts;
}

fetchAllProducts().then(allProducts => {
    try {
        fs.writeFileSync(OUTPUT_FILE, JSON.stringify(allProducts, null, 2));
        console.log(`Product data saved to ${OUTPUT_FILE}`);
    } catch (err) {
        console.error('Failed to write to products.json', err);
    }
}).catch(err => {
    console.error('Error in fetching process:', err);
});