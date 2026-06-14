#!/bin/sh
# Generate Doctrine proxy classes only if not already generated
if [ -z "$(ls -A /var/tmp/doctrine-proxies 2>/dev/null)" ]; then
    echo "Generating Doctrine proxy classes..."
    php /app/src/GenerateProxies.php
fi
exec "$@"
