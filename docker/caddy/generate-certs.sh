#!/bin/bash

set -e

certs_dir="$(dirname "$0")/certs"
domain="whisper.money.local"
cert_file="${certs_dir}/${domain}.pem"
key_file="${certs_dir}/${domain}-key.pem"

mkdir -p "$certs_dir"

if command -v mkcert &> /dev/null; then
    if [ ! -f "$(mkcert -CAROOT)/rootCA.pem" ]; then
        echo "Installing local CA..."
        mkcert -install
    fi

    echo "Generating certificates for ${domain}..."
    mkcert -cert-file "$cert_file" -key-file "$key_file" "$domain" "*.${domain}"
    echo "Certificates generated successfully!"
    echo "Files created:"
    echo "  - ${cert_file}"
    echo "  - ${key_file}"
elif command -v openssl &> /dev/null; then
    echo "Creating self-signed certificates (browser will show a warning)..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$key_file" \
        -out "$cert_file" \
        -subj "/CN=${domain}/O=Development/C=US" \
        -addext "subjectAltName=DNS:${domain},DNS:*.${domain},IP:127.0.0.1" \
        2>/dev/null

    chmod 644 "$cert_file"
    chmod 600 "$key_file"
    echo "Self-signed certificates created"
    echo "Note: Your browser will show a security warning."
else
    echo "Error: Neither mkcert nor openssl is available"
    echo "Please install mkcert (recommended) or openssl"
    exit 1
fi
