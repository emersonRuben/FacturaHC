FROM php:8.3-fpm

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip soap

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar directorio de trabajo
WORKDIR /app

# Copiar archivos de dependencias
COPY composer.json composer.lock ./

# Instalar dependencias de PHP (sin dev para producción)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copiar el resto del código
COPY . .

# Crear directorios necesarios y permisos
RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Copiar script de inicio y convertir line endings
COPY start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

# Exponer puerto
EXPOSE ${PORT:-8000}

# Comando de inicio
CMD ["/bin/sh", "/usr/local/bin/start.sh"]
