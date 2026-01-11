ARG BUILD_FROM
FROM $BUILD_FROM

WORKDIR /data

RUN apk -U upgrade && apk add --no-cache \
    curl \
    nginx \
    php83-fpm \
    php83-pdo \
    php83-pdo_sqlite \
    php83-session \
    php83-curl \
    sqlite \
    python3 \
    && ln -s /usr/sbin/php-fpm83 /usr/sbin/php-fpm \
    && addgroup -S php \
    && adduser -S -G php php \
    && rm -rf /var/cache/apk/* /etc/nginx/http.d/* /etc/php83/conf.d/* /etc/php83/php-fpm.d/* \
    && { \
    echo "extension=pdo.so"; \
    echo "extension=pdo_sqlite.so"; \
    } > /etc/php83/conf.d/20-pdo.ini \
    && { \
    echo "extension=session.so"; \
    } > /etc/php83/conf.d/21-session.ini \
    && { \
    echo "extension=curl.so"; \
    } > /etc/php83/conf.d/22-curl.ini

#RUN mkdir -p /grace_config && \
#    chown -R php:nginx /grace_config && \
#    chmod -R 660 /grace_config && \
#    touch /grace_config/grace.db && \
#    chown php:nginx /grace_config/grace.db

COPY files/general files/php83 /
RUN chmod +x /etc/services.d/*/run /etc/services.d/*/finish
RUN chmod +x /etc/cont-init.d/*.sh
RUN chown -R php:nginx /www/public
RUN chmod -R 775 /www/public

ENTRYPOINT ["/init"]
