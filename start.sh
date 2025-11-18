#!/bin/sh
set -e

echo "🧹 Limpiando cache de Laravel..."
php artisan config:clear
php artisan route:clear
php artisan cache:clear

echo "🗄️ Ejecutando migraciones..."
php artisan migrate --force

echo "📚 Generando documentación Swagger..."
php artisan l5-swagger:generate

echo "🚀 Iniciando servidor Laravel en puerto ${PORT:-8000}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
