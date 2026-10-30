# notificaciones-service — PHP 8 sobre Apache (varios procesos: atiende peticiones concurrentes,
# necesario porque los servicios se llaman entre sí; "php -S" atiende una a la vez)
FROM php:8.3-apache

# Este servicio no usa base de datos (solo recibe avisos y envía correos)

# Apache escucha en el puerto asignado al servicio (8085) y envía todo a public/index.php
RUN sed -i 's/^Listen 80$/Listen 8085/' /etc/apache2/ports.conf
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-servicio.ini

COPY public/ /var/www/html/public/
COPY src/    /var/www/html/src/

EXPOSE 8085
