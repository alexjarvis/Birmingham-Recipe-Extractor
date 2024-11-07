# Start with a minimal Node.js image that includes Node and npm
FROM node:18-alpine

# Install PHP, cURL, and php-dom for DOMDocument support
RUN apk add --no-cache php php-curl php-dom curl

# Set the working directory in the container
WORKDIR /app

# Copy the project files into the container
COPY . .

# Ensure the shell script has Unix line endings and executable permissions
RUN sed -i 's/\r$//' /app/run.sh && chmod +x /app/run.sh

# Run the shell script, which will execute Node.js and PHP scripts
CMD ["/app/run.sh"]