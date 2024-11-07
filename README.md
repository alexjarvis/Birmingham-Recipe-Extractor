docker build -t birmingham-recipe-scraper .
docker run --rm -v "$PWD/output:/app/output" birmingham-recipe-scraper
