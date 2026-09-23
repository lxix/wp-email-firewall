#!/usr/bin/env bash
# Starts the test environment, installs WordPress and activates the plugin.
# Extra wordpress.org plugins to install and activate can be passed as arguments:
#   test-env/setup.sh woocommerce wpforms-lite
set -euo pipefail

cd "$(dirname "$0")"

docker compose up -d --wait

if ! ./wp core is-installed 2>/dev/null; then
  ./wp core install \
    --url="http://localhost:${WP_PORT:-8080}" \
    --title="WP Email Firewall" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@wp-email-firewall.test \
    --skip-email
fi

./wp plugin activate wp-email-firewall

if [ $# -gt 0 ]; then
  ./wp plugin install "$@" --activate
fi

echo "WordPress: http://localhost:${WP_PORT:-8080}/wp-admin/ (admin / admin)"
echo "Mailpit:   http://localhost:${MAILPIT_PORT:-8025}/"
