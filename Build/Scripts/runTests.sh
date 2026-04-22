#!/usr/bin/env bash
#
# Thin harness that runs the extension's quality checks uniformly
# from a developer machine or a CI runner. Use the `-s` flag to pick
# a suite; default is `unit`.
#
# Usage:
#   Build/Scripts/runTests.sh                    # unit tests
#   Build/Scripts/runTests.sh -s cs              # TYPO3 coding standards
#   Build/Scripts/runTests.sh -s phpstan         # static analysis
#   Build/Scripts/runTests.sh -s unit            # PHPUnit unit suite
#   Build/Scripts/runTests.sh -s functional      # PHPUnit functional suite
#   Build/Scripts/runTests.sh -s mutation        # Infection mutation testing
#   Build/Scripts/runTests.sh -s ci              # cs + phpstan + unit + functional
#
# Environment variables picked up from typo3/testing-framework
# (typo3DatabaseHost, typo3DatabaseName, ...) are forwarded to PHPUnit.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

suite="unit"

while getopts "s:h" opt; do
    case "$opt" in
        s) suite="$OPTARG" ;;
        h)
            sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unknown flag. Use -h for usage." >&2
            exit 2
            ;;
    esac
done

require_vendor() {
    if [[ ! -x "vendor/bin/$1" ]]; then
        echo "vendor/bin/$1 is missing. Run 'composer install' first." >&2
        exit 1
    fi
}

case "$suite" in
    cs)
        require_vendor php-cs-fixer
        exec vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
        ;;
    phpstan)
        require_vendor phpstan
        exec vendor/bin/phpstan analyse --memory-limit=1G --no-progress
        ;;
    unit)
        require_vendor phpunit
        exec vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
        ;;
    functional)
        require_vendor phpunit
        exec vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
        ;;
    mutation)
        require_vendor infection
        exec vendor/bin/infection --threads=4 --no-progress
        ;;
    ci)
        require_vendor php-cs-fixer
        require_vendor phpstan
        require_vendor phpunit
        vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
        vendor/bin/phpstan analyse --memory-limit=1G --no-progress
        vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
        exec vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
        ;;
    *)
        echo "Unknown suite: $suite (valid: cs, phpstan, unit, functional, mutation, ci)" >&2
        exit 2
        ;;
esac
