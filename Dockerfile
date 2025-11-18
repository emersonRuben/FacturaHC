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

# Exponer puerto 8000
EXPOSE 8000

# Comando de inicio - Limpiar cache, migrar, generar swagger y servir
CMD php artisan config:clear && \
    php artisan route:clear && \
    php artisan cache:clear && \
    php artisan migrate --force && \
    echo "Migraciones completadas" && \
    php artisan l5-swagger:generate && \
    echo "Swagger generado" && \
    echo "Iniciando servidor en puerto ${PORT:-8000}..." && \
    php artisan serve --host=0.0.0.0 --port=${PORT:-8000} --verbose
