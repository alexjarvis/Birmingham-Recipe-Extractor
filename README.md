docker build -t birmingham-recipe-scraper .
docker run --rm -v "$PWD:/app/output" birmingham-recipe-scraper