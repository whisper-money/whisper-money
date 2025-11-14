#!/bin/bash

set -e

if ! command -v mkcert &> /dev/null; then
    echo "mkcert is not installed. Installing..."
    
    if [[ "$OSTYPE" == "darwin"* ]]; then
        if command -v brew &> /dev/null; then
            brew install mkcert
            brew install nss
        else
            echo "Please install Homebrew first, then run: brew install mkcert nss"
            exit 1
        fi
    else
        echo "Please install mkcert manually. Visit: https://github.com/FiloSottile/mkcert"
        exit 1
    fi
fi

if [ ! -f "$(mkcert -CAROOT)/rootCA.pem" ]; then
    echo "Installing local CA..."
    mkcert -install
fi

echo "Generating certificates for whispermoney.test..."
cd "$(dirname "$0")/certs"
mkcert -key-file whispermoney.test-key.pem -cert-file whispermoney.test.pem whispermoney.test

echo "Certificates generated successfully!"
echo "Files created:"
echo "  - traefik/certs/whispermoney.test.pem"
echo "  - traefik/certs/whispermoney.test-key.pem"
