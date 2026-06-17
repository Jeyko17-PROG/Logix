FROM php:8.4-apache

# Instalar dependencias del sistema y extensiones de PHP requeridas
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Activar el módulo de reescritura de Apache para Laravel (mod_rewrite)
RUN a2enmod rewrite

# Instalar Composer de forma global
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar los archivos del backend al contenedor
COPY . .

# Instalar dependencias de Laravel ignorando restricciones de plataforma
RUN composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-reqs

# Configurar los permisos correctos para Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Apuntar Apache a la carpeta pública de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Crear la base de datos SQLite y darle permisos a Apache (www-data)
RUN mkdir -p /var/www/html/database && touch /var/www/html/database/database.sqlite && chown -R www-data:www-data /var/www/html/database

# Exponer el puerto por defecto de Render
EXPOSE 80

# Ejecutar migraciones antes de arrancar Apache
CMD php artisan migrate --force && apache2-foreground
