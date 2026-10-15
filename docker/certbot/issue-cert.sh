#!/bin/sh
# Replace the temporary self-signed certificate with a real Let's Encrypt one.
# Run: docker compose --profile certissue run --rm certissue
#      docker compose exec nginx nginx -s reload
set -e

: "${DOMAIN:?DOMAIN is required}"

if [ -f "/etc/letsencrypt/renewal/${DOMAIN}.conf" ]; then
    echo "[certissue] a Let's Encrypt certificate already exists for ${DOMAIN}; certbot renew handles it."
    exit 0
fi

# Drop the temporary certificate created by certinit.
rm -rf "/etc/letsencrypt/live/${DOMAIN}" "/etc/letsencrypt/archive/${DOMAIN}"

if [ -n "${CERTBOT_EMAIL}" ]; then
    email_args="--email ${CERTBOT_EMAIL}"
else
    email_args="--register-unsafely-without-email"
fi

# shellcheck disable=SC2086
certbot certonly --webroot -w /var/www/certbot -d "${DOMAIN}" \
    ${email_args} --agree-tos --non-interactive
