FROM dunglas/frankenphp:1-php8.2

# Extensions PHP requises par le site (gd pour phpspreadsheet, zip, MySQL)
RUN install-php-extensions gd zip pdo_mysql mysqli

# Copie le contenu de public/ dans la racine servie par FrankenPHP
COPY public/ /app/public/

# Configuration du serveur : port dynamique Railway + blocage des fichiers sensibles
COPY Caddyfile /etc/frankenphp/Caddyfile

# Port par defaut en local ; Railway fournit $PORT au runtime (lu par le Caddyfile)
ENV PORT=8080
EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
