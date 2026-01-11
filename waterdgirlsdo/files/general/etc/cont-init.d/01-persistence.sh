#!/usr/bin/with-contenv bashio

# Ensure data directory is writable by the web server users
chown -R php:nginx /data
chmod -R 775 /data

# If the database doesn't exist in /data, move the initial one there
if [ ! -f /data/waterdgirlsdo.db ]; then
    if [ -f /www/public/waterdgirlsdo.db ]; then
        cp /www/public/waterdgirlsdo.db /data/waterdgirlsdo.db
        chown php:nginx /data/waterdgirlsdo.db
        chmod 664 /data/waterdgirlsdo.db
        bashio::log.info "Initial database copied to /data"
    fi
fi
