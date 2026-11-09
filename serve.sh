#!/usr/bin/env bash
# Start the inventory system for every device on the network, with clean addresses (no .php).
# Open it from another device at  http://<this computer's IP>:8000   (see: hostname -I)
cd "$(dirname "$0")" && exec php -S 0.0.0.0:"${1:-8000}" router.php
