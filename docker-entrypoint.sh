#!/bin/sh

# Making sure php has write permission for volume folders
chown -R www-data:www-data /var/www/html/backend/config/
chown -R www-data:www-data /var/www/html/esmira_data

# Opt-in: also serve the whole site under a path prefix (e.g. ESMIRA_PATH_ALIAS=/esmira). The participant PWA calls
# {prefix}/api/*, which in production is supplied by the reverse proxy; the demo/verify setups have no proxy.
# Unset (the default) leaves Apache's configuration untouched.
if [ -n "${ESMIRA_PATH_ALIAS:-}" ]; then
	printf 'Alias "%s" "/var/www/html"\n' "${ESMIRA_PATH_ALIAS%/}" > /etc/apache2/conf-enabled/esmira-path-alias.conf
fi

# Docker image could have been updated, so we check for migrations:
php -r "require_once '/var/www/html/backend/autoload.php'; backend\MigrationManager::autoRun();"

# Start cron, which runs the web-push sender every minute (cli/push_send_due.php).
service cron start 2>/dev/null || cron || true

# Running passed entry point:
exec "$@"