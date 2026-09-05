# Official PHP CLI image on Alpine. It already ships curl, dom, json, and mbstring,
# so no extra packages are needed.
FROM php:8.5-cli-alpine

# Set the working directory in the container
WORKDIR /app

# Copy the project files into the container
COPY . .

CMD ["php", "/app/run.php"]
