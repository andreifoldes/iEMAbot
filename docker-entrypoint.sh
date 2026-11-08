#!/bin/sh

# Making sure php has write permission for volume folders
chown -R www-data:www-data /var/www/html/backend/config/
chown -R www-data:www-data /var/www/html/esmira_data

# Credentials are for the web user only. The app keeps this when it rewrites them; this covers installs
# whose files were written earlier with the default umask. Only removes access for group and others,
# the owner (www-data, set above) keeps its access. Best effort: a missing file or folder is fine.
chmod go-rwx /var/www/html/backend/config/configs.php /var/www/html/esmira_data/.logins 2>/dev/null || true
chmod -R go-rwx /var/www/html/esmira_data/.loginToken 2>/dev/null || true
find /var/www/html/esmira_data/studies -type d -name .wearables_tokens -exec chmod -R go-rwx {} + 2>/dev/null || true

# Opt-in: also serve the whole site under a path prefix (e.g. ESMIRA_PATH_ALIAS=/esmira). The participant PWA calls
# {prefix}/api/*, which in production is supplied by the reverse proxy; the demo/verify setups have no proxy.
# Unset (the default) leaves Apache's configuration untouched.
if [ -n "${ESMIRA_PATH_ALIAS:-}" ]; then
	printf 'Alias "%s" "/var/www/html"\n' "${ESMIRA_PATH_ALIAS%/}" > /etc/apache2/conf-enabled/esmira-path-alias.conf
fi

# Docker image could have been updated, so we check for migrations:
php -r "require_once '/var/www/html/backend/autoload.php'; backend\MigrationManager::autoRun();"

# Settings that follow from the server's public address (IEMABOT_PUBLIC_URL, see deploy/selfhost/). Does nothing when
# it is unset. A bad value must not keep the server from starting: the self-host kit's doctor.sh reports it.
su -s /bin/sh -c 'php /var/www/html/cli/selfhost.php apply-env' www-data || echo "cli/selfhost.php apply-env failed"

# Start cron, which runs the web-push sender every minute (cli/push_send_due.php).
service cron start 2>/dev/null || cron || true

# Running passed entry point:
exec "$@"