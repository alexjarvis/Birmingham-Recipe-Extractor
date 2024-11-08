# Start with a minimal PHP image based on Alpine
FROM php:8.3-cli-alpine

# Install additional required packages
RUN apk add --no-cache \
    curl \
    php-curl \
    php-json \
    php-dom

# Set the working directory in the container
WORKDIR /app

# Copy the project files into the container
COPY . .

# Run the shell script, which will execute Node.js and PHP scripts
CMD ["php /app/run.php"]