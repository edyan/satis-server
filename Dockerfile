FROM        composer/satis

ADD         --chown=www-data app /app
COPY        --from=composer:1 /usr/bin/composer /usr/bin/composer
COPY        docker-entrypoint.sh /docker-entrypoint.sh

RUN         chmod 755 /docker-entrypoint.sh /usr/bin/composer
RUN         mkdir /composer && \
            chown www-data:www-data /build /composer

USER        www-data

RUN         mkdir $HOME/.ssh && \
            printf "Host *\n    StrictHostKeyChecking no" > $HOME/.ssh/config && cat $HOME/.ssh/config && exit 1

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


ENTRYPOINT  ["/docker-entrypoint.sh"]
CMD         ["php", "-S", "0.0.0.0:8080", "-t", "/app/public", "/app/public/index.php"]
