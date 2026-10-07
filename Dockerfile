FROM php:8.2-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache to listen on dynamic Railway PORT
ENV PORT=80
RUN sed -i "s/Listen 80/Listen \${PORT}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:\${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Set working directory
WORKDIR /var/www/html

# Copy source code
COPY . /var/www/html/

# Create uploads folder and grant webserver permissions
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html/uploads && \
    chmod -R 775 /var/www/html/uploads

EXPOSE 80

CMD ["apache2-foreground"]
