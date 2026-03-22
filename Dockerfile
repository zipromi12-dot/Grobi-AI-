# Используем официальный образ PHP с Apache
FROM php:8.2-apache

# Устанавливаем расширение для работы с PostgreSQL (если решишь перейти на PDO)
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pgsql pdo_pgsql

# Копируем все файлы твоего проекта в папку сервера
COPY . /var/www/html/

# Открываем порт 80
EXPOSE 80
