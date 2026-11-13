# syntax=docker/dockerfile:1.27@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e

# Build inputs are pinned by version AND digest/checksum (security audit, tracker item 6), so a rebuild
# of the same commit gets the same bytes and a moved tag or a replaced release asset fails the build
# instead of silently changing what is deployed. The tag stays in each comment for humans.
# To bump one, change the version and the pin together:
#   base images : docker buildx imagetools inspect <image>:<tag>   (use the top-level "Digest:")
#   installer   : curl -fsSL <release url> | sha256sum             (compare with the digest shown on the release page)
#   PHP packages: edit src/backend/composer.json, run `composer update` inside this image's PHP, commit composer.lock

# This file is expected to be run by github actions after ESMira has already been built
# If you use it locally, you have to make sure there is a working dist/ directory by running:
# npm install
# npm run prod

# php:8.5.11-apache
FROM php:8.5.12-apache@sha256:08af36df144462d84c7f16190557eec06cb9f656882685610b712a15eec54d46
# mlocati/docker-php-extension-installer 2.13.2 (the checksum is of the release asset install-php-extensions)
ADD --chmod=0755 --checksum=sha256:27fa8d80bd4e22feb9ddcadff1ca40c8f3e8ea942eb2dd8f446ed1afd2f2f0ab https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
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
# which backend/autoload.php picks up. composer.json and composer.lock ship in dist/backend.
# `composer install` takes exactly the versions (and archive hashes) in composer.lock and fails if the lock
# does not match composer.json; it never resolves new versions.
# composer:2.11.1
COPY --from=composer:2.11.1@sha256:3d1eb9437516b0ba88c46a0bb05826451a1ab178d29adacb5ded26dad0899ae4 /usr/bin/composer /usr/bin/composer
RUN cd /var/www/html/backend \
	&& composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
	&& chown -R www-data:www-data /var/www/html/backend/vendor

# Cron: run the web-push sender every minute (see cli/push_send_due.php).
# NOTE: use the ABSOLUTE php path — cron runs with a minimal PATH (/usr/bin:/bin)
# that excludes /usr/local/bin where the php:8.5-apache base image puts php, so a
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

# Cron: file participants' error reports as GitHub issues (see cli/github_issues_send.php;
# does nothing until cli/github_issues_setup.php configured a repository and token).
RUN printf '* * * * * www-data /usr/local/bin/php /var/www/html/cli/github_issues_send.php >> /var/log/esmira_github_issues.log 2>&1\n' > /etc/cron.d/esmira-github-issues \
	&& chmod 0644 /etc/cron.d/esmira-github-issues \
	&& touch /var/log/esmira_github_issues.log && chown www-data:www-data /var/log/esmira_github_issues.log

# Enable mod rewrite
RUN a2enmod rewrite & a2enmod md & a2enmod ssl
# mod_headers sends the security headers set in .htaccess (see src/.htaccess and website/docs/deployment/security-headers.md).
# The base image does not enable the module, so without this line those headers (and the no-cache header for the
# PWA's service worker) are silently dropped.
RUN a2enmod headers
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



# The code lives in this image, so the server must not update itself in place: a new container would bring the old code
# back on top of data the update had already migrated. See backend/SelfHost.php.
ENV IEMABOT_CONTAINER_IMAGE=1
# The self-host kit that goes with this image: update.sh takes its scripts from here, so the two never drift apart.
COPY ./deploy/selfhost /opt/iemabot-kit
# The demo study a new self-hosted server starts with (install.sh runs `cli/selfhost.php demo`), outside the web root.
COPY ./demo/demo-study.json /opt/iemabot-demo/demo-study.json

# Setup entry script:
COPY ./docker-entrypoint.sh /
RUN chmod +x /docker-entrypoint.sh


## Switch to a non-privileged user (defined in the base image) that the app will run under.
## See https://docs.docker.com/go/dockerfile-user-best-practices/
#USER www-data


ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
