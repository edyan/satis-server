FROM        composer/satis

COPY        --from=composer:1 /usr/bin/composer /usr/bin/composer
COPY        docker-entrypoint.sh /docker-entrypoint.sh

RUN         chmod 755 /docker-entrypoint.sh /usr/bin/composer

RUN         apk add --no-cache libzip libzip-dev && \
            docker-php-ext-install zip && \
            apk del libzip-dev

RUN         echo "post_max_size = 32M" > /usr/local/etc/php/conf.d/satis-server.ini && \
            echo "upload_max_filesize = 32M" >> /usr/local/etc/php/conf.d/satis-server.ini && \
            echo "max_execution_time = 360" >> /usr/local/etc/php/conf.d/satis-server.ini

RUN         mkdir /composer && \
            chown www-data:www-data /build /composer

# Apply Patch
COPY        force-clone-protocol.patch /tmp/force-clone-protocol.patch
RUN         apk add patch && \
            cd /satis && \
            patch -p0 < /tmp/force-clone-protocol.patch && \
            apk del patch

ADD         --chown=www-data app /app
USER        www-data

RUN         mkdir $HOME/.ssh && \
            printf "Host *\n    StrictHostKeyChecking no" > $HOME/.ssh/config

WORKDIR     /app
RUN         composer install \
                --no-dev \
                --no-progress \
                --no-suggest \
                --prefer-dist \
                --optimize-autoloader


VOLUME      ["/build"]
EXPOSE      8080

# At the end as it changes everytime ;)
ARG         BUILD_DATE
ARG         DOCKER_TAG
ARG         VCS_REF
LABEL       maintainer="Emmanuel Dyan <emmanueldyan@gmail.com>" \
            org.label-schema.build-date=${BUILD_DATE} \
            org.label-schema.name=${DOCKER_TAG} \
            org.label-schema.description="Docker PHP Image based on Debian and including main modules" \
            org.label-schema.url="https://cloud.docker.com/u/edyan/repository/docker/edyan/php" \
            org.label-schema.vcs-url="https://github.com/edyan/docker-php" \
            org.label-schema.vcs-ref=${VCS_REF} \
            org.label-schema.schema-version="1.0" \
            org.label-schema.vendor="edyan" \
            org.label-schema.docker.cmd="docker run -d --rm ${DOCKER_TAG}"

ENV         PHP_CLI_SERVER_WORKERS 4
ENTRYPOINT  ["/docker-entrypoint.sh"]
CMD         ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
