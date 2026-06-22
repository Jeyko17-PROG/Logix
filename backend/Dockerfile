FROM php:8.4-apache

# Instalar dependencias del sistema y extensiones de PHP requeridas
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

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

# Asegurar permisos también para la carpeta database (SQLite)
RUN mkdir -p /var/www/html/database \
    && chown -R www-data:www-data /var/www/html/database \
    && chmod -R 775 /var/www/html/database

# Apuntar Apache a la carpeta pública de Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Exponer el puerto por defecto de Render
EXPOSE 80

# Copiar el script de arranque que fija permisos y ejecuta migraciones, luego arranca Apache
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh
CMD /usr/local/bin/start.sh
