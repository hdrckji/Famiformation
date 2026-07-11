FROM dunglas/frankenphp:1-php8.3

# Extensions PHP requises par le site (gd pour phpspreadsheet, zip, MySQL)
RUN install-php-extensions gd zip pdo_mysql mysqli

# ffmpeg : compression vidéo 720p ; poppler-utils : extraction des images des PDF (pdfimages)
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg poppler-utils && rm -rf /var/lib/apt/lists/*

# Limites d'upload relevées (on accepte une vidéo brute confortable ~500 Mo ; elle est
# ensuite compressée côté serveur). upload_max_filesize < post_max_size (PDF + vidéo possibles).
RUN printf "upload_max_filesize=512M\npost_max_size=540M\nmemory_limit=512M\nmax_execution_time=600\n" > "$PHP_INI_DIR/conf.d/zz-uploads.ini"

# Copie le contenu de public/ dans la racine servie par FrankenPHP
COPY public/ /app/public/

# Copie l'app FamiJob comme sous-dossier de la racine web (accessible via /famijob/)
COPY Famijob/ /app/public/famijob/

# Configuration du serveur : port dynamique Railway + blocage des fichiers sensibles
COPY Caddyfile /etc/frankenphp/Caddyfile

# Port par defaut en local ; Railway fournit $PORT au runtime (lu par le Caddyfile)
ENV PORT=8080
EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
