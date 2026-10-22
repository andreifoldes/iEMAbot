# syntax=docker/dockerfile:1

# This file is expected to be run by github actions after ESMira has already been built
# If you use it locally, you have to make sure there is a working dist/ directory by running:
# npm install
# npm run prod

FROM php:8.3.36-apache
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
# gmp + mbstring + curl are required by minishlink/web-push (VAPID signing,
# payload encryption, and the HTTP client used to reach push services).
# sodium encrypts stored wearable OAuth tokens at rest (backend/wearables/).
RUN install-php-extensions zip gmp mbstring curl sodium
# pdo_sqlite backs the collected-data database (esmira_data/iemabot.sqlite, see backend/sqlite/).
# It is compiled into the official php image; fail the build loudly if that ever stops being true.
RUN php -m | grep -qi '^pdo_sqlite$'

#RUN apt-get update
#RUN apt-get install -y php8.0-zip

# Copy app files from the app directory.
COPY --chown=www-data:www-data ./dist /var/www/html

# Install the backend's Composer dependencies (web push) into backend/vendor,
# which backend/autoload.php picks up. composer.json ships in dist/backend.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html/backend \
	&& composer update --no-dev --no-interaction --optimize-autoloader \
	&& chown -R www-data:www-data /var/www/html/backend/vendor

# Cron: run the web-push sender every minute (see cli/push_send_due.php).
# NOTE: use the ABSOLUTE php path — cron runs with a minimal PATH (/usr/bin:/bin)
# that excludes /usr/local/bin where the php:8.3-apache base image puts php, so a
# bare `php` fails with "php: not found" and the sender silently never runs.
RUN apt-get update && apt-get install -y --no-install-recommends cron && rm -rf /var/lib/apt/lists/*
RUN printf '* * * * * www-data /usr/local/bin/php /var/www/html/cli/push_send_due.php >> /var/log/esmira_push.log 2>&1\n' > /etc/cron.d/esmira-push \
	&& chmod 0644 /etc/cron.d/esmira-push \
	&& touch /var/log/esmira_push.log && chown www-data:www-data /var/log/esmira_push.log

# Cron: poll connected wearables once an hour (see cli/wearables_sync.php).
# Absolute php path for the same reason as the push sender above.
RUN printf '0 * * * * www-data /usr/local/bin/php /var/www/html/cli/wearables_sync.php >> /var/log/esmira_wearables.log 2>&1\n' > /etc/cron.d/esmira-wearables \
	&& chmod 0644 /etc/cron.d/esmira-wearables \
	&& touch /var/log/esmira_wearables.log && chown www-data:www-data /var/log/esmira_wearables.log

# Cron: chat channels (Telegram; see backend/channels/). The dispatcher opens questionnaires
# that became due; the poller receives Telegram updates by long polling for ~55 s per run
# (it exits at once when no bot is configured or the webhook transport is selected).
RUN printf '* * * * * www-data /usr/local/bin/php /var/www/html/cli/channels_dispatch.php >> /var/log/esmira_channels.log 2>&1\n* * * * * www-data /usr/local/bin/php /var/www/html/cli/telegram_poll.php >> /var/log/esmira_channels.log 2>&1\n' > /etc/cron.d/esmira-channels \
	&& chmod 0644 /etc/cron.d/esmira-channels \
	&& touch /var/log/esmira_channels.log && chown www-data:www-data /var/log/esmira_channels.log

# Enable mod rewrite
RUN a2enmod rewrite & a2enmod md & a2enmod ssl
RUN service apache2 restart

# Use the default production configuration for PHP runtime arguments, see
# https://github.com/docker-library/docs/tree/master/php#configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Set permissions:
RUN chown -R www-data:www-data /var/www/html


# Define volumes:
VOLUME /var/www/html/backend/config/
VOLUME /var/www/html/esmira_data/
VOLUME /etc/apache2/sites-enabled/



# Setup entry script:
COPY ./docker-entrypoint.sh /
RUN chmod +x /docker-entrypoint.sh


## Switch to a non-privileged user (defined in the base image) that the app will run under.
## See https://docs.docker.com/go/dockerfile-user-best-practices/
#USER www-data


ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
