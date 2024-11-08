A simple, unofficial utility to extract recipes from [Birmingham Pen Company](https://www.birminghampens.com) ink formulas.

# Run Locally
`chmod +x run.sh`

`./run.sh`

# Run in Docker
`docker build -t birmingham-recipe-extractor .`

`docker run --rm -v "$PWD/output:/app/output" birmingham-recipe-extractor`
