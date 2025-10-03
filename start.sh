#!/bin/bash

# Start PHP-FPM
php-fpm -D

# Start Nginx in foreground
nginx -g 'daemon off;'
