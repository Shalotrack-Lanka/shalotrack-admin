#!/bin/sh
# Runs Laravel's scheduler once a minute (what `* * * * * php artisan schedule:run` does on a normal server).
# Started by /start.sh only when RUN_SCHEDULER=1. Runs as www-data so every file it touches in storage/
# stays owned by the same user as php-fpm (running as root would leave root-owned cache/log files that
# break the web app).
cd /var/www/html || exit 1
while true; do
    # Each run in the background so a slow job can never delay the next minute's tick.
    ( su -s /bin/sh www-data -c 'php artisan schedule:run' >/dev/null 2>&1 ) &
    # Sleep to the start of the next minute. sed drops a leading zero (sh arithmetic reads 08 as octal).
    s=$(date +%S | sed 's/^0//')
    sleep $((60 - ${s:-0}))
done
