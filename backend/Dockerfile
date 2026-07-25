FROM dunglas/frankenphp:1.12.6-php8.5.8-bookworm

# Le Caddy de bord est la seule origine publique (`localhost:8080`) ; ce
# conteneur n'est joint qu'en interne, en HTTP. `:80` sans nom d'hôte désactive
# l'HTTPS automatique de Caddy : aucun certificat à générer pour un service que
# personne n'atteint en direct.
ENV SERVER_NAME=:80

# Horloge système du conteneur : UTC. Vaut pour glibc, donc pour les timestamps
# des logs Caddy et pour tout sous-process. PHP a sa propre base de fuseaux et
# ignore `TZ` — voir `conf.d/timezone.ini` plus bas.
ENV TZ=UTC

WORKDIR /app

# curl        : sonde du HEALTHCHECK ci-dessous
# libcap2-bin : fournit `setcap`, pour autoriser un process non-root à écouter
#               sur le port 80 (privilégié)
RUN apt-get update \
    && apt-get install --no-install-recommends --yes \
        curl \
        libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

# `install-php-extensions` est fourni par l'image FrankenPHP : il installe les
# dépendances système de chaque extension puis les retire après compilation.
#   pdo_pgsql : doctrine/dbal      intl : Symfony
#   zip       : Composer           opcache : toujours
RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        zip

# Configuration PHP de développement (erreurs affichées, assertions actives).
# L'image livre aussi `php.ini-production`, qui servira le jour où ce Dockerfile
# gagnera une cible de production.
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Fuseau de PHP forcé à UTC. Les fichiers de `conf.d/` sont chargés après
# `php.ini`, donc cette valeur gagne quoi qu'il arrive — et on ne réécrit pas
# le `php.ini` livré par l'image.
#
# UTC laissé implicite serait un piège : `date.timezone` non renseigné vaut UTC
# aujourd'hui, mais alors le fuseau dépend d'un défaut, pas d'une décision. Or
# les horodatages des messages arbitrent l'ordre d'affichage côté client ; ils
# doivent être comparables entre le backend, PostgreSQL et le navigateur.
RUN printf '[date]\ndate.timezone = UTC\n' > "$PHP_INI_DIR/conf.d/timezone.ini"

# Composer vit dans le conteneur backend : `php`, `composer` et `vendor/bin/*`
# n'existent nulle part sur la machine hôte.
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

# Les fichiers écrits par le conteneur (var/cache, var/log, vendor/) atterrissent
# dans le bind mount, donc sur le disque de l'hôte. Tourner en root les y
# rendrait root, et un `rm -rf var/cache` depuis l'hôte échouerait. On réaligne
# donc l'UID/GID de `www-data` sur ceux de l'utilisateur hôte.
# Override si besoin : `docker compose build --build-arg USER_ID=$(id -u) backend`
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN groupmod --non-unique --gid "${GROUP_ID}" www-data \
    && usermod --non-unique --uid "${USER_ID}" --gid "${GROUP_ID}" www-data \
    # sans cette capability, un process non-root ne peut pas binder le :80
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /config/caddy /data/caddy \
    && chown -R www-data:www-data /app /config/caddy /data/caddy

USER www-data

# Le code source arrive par bind mount (`./backend:/app`) et `vendor/` est
# installé par `make install` : rien n'est copié dans l'image. Une cible de
# production copierait les sources et lancerait `composer install --no-dev`.

EXPOSE 80

# L'API d'admin de Caddy expose ses métriques sur le 2019 : c'est la preuve que
# le serveur est réellement en train de servir, pas seulement démarré.
HEALTHCHECK --start-period=30s --interval=10s --timeout=3s --retries=5 \
    CMD curl --fail --silent --output /dev/null http://localhost:2019/metrics || exit 1

# CMD héritée de l'image de base : `frankenphp run --config /etc/caddy/Caddyfile`.
# Le Caddyfile par défaut sert `/app/public` via `php_server` — exactement ce
# qu'il faut, donc aucune configuration Caddy à écrire côté backend.
