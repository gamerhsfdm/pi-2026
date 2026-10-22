#!/usr/bin/env sh
set -eu
find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l
