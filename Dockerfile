FROM php:8.2-apache

# Librairies systeme requises par les extensions PHP (gd, zip)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP requises par le site (gd pour phpspreadsheet)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip pdo_mysql mysqli

# Active la reecriture d'URL
RUN a2enmod rewrite

# Un seul MPM Apache actif : prefork uniquement (evite "More than one MPM loaded")
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork

# Copie le contenu de public/ dans la racine web d'Apache
COPY public/ /var/www/html/

# Autorise les .htaccess (regles mod_rewrite) dans la racine web
RUN printf '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/allow-override.conf \
 && a2enconf allow-override

# Apache ecoute sur le port fourni par Railway ($PORT), 80 par defaut en local
ENV PORT=80
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/:80>/:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && echo 'export PORT=${PORT:-80}' >> /etc/apache2/envvars

EXPOSE 80

CMD ["apache2-foreground"]
