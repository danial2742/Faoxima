#!/bin/sh
# Create a throwaway self-signed certificate so nginx can boot on :443
# before a real Let's Encrypt certificate has been issued.
set -e

: "${DOMAIN:?DOMAIN is required}"

dir="/etc/letsencrypt/live/${DOMAIN}"

if [ -f "${dir}/fullchain.pem" ] && [ -f "${dir}/privkey.pem" ]; then
    echo "[certinit] certificate already present for ${DOMAIN}"
    exit 0
fi

mkdir -p "${dir}"
openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
    -keyout "${dir}/privkey.pem" \
    -out "${dir}/fullchain.pem" \
    -subj "/CN=${DOMAIN}"
echo "[certinit] created temporary self-signed certificate for ${DOMAIN}"
