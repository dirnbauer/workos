#!/usr/bin/env bash
#
# Thin harness that runs the extension's quality checks uniformly
# from a developer machine or a CI runner. Use the `-s` flag to pick
# a suite; default is `unit`.
#
# Usage:
#   Build/Scripts/runTests.sh                    # unit tests
#   Build/Scripts/runTests.sh -s lint            # php -l on every extension PHP file
#   Build/Scripts/runTests.sh -s cs              # TYPO3 coding standards (dry-run)
#   Build/Scripts/runTests.sh -s phpstan         # static analysis (level max, policy >= 8)
#   Build/Scripts/runTests.sh -s unit            # PHPUnit unit suite
#   Build/Scripts/runTests.sh -s functional      # PHPUnit functional suite
#   Build/Scripts/runTests.sh -s architecture    # phpat layering rules only
#   Build/Scripts/runTests.sh -s mutation        # Infection mutation testing
#   Build/Scripts/runTests.sh -s ci              # lint + cs + phpstan + unit + functional + architecture
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

lint_php_files() {
    local status=0 output
    while IFS= read -r -d '' file; do
        if ! output="$(php -l "$file" 2>&1)"; then
            printf '%s\n' "$output" >&2
            status=1
        fi
    done < <(find Classes Configuration Tests ext_localconf.php -name '*.php' -print0)
    return "$status"
}

case "$suite" in
    lint)
        lint_php_files
        echo "PHP syntax OK."
        ;;
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
    architecture)
        # phpat rules are registered as PHPStan rules in phpstan.neon and run
        # at every level; level 0 keeps this job fast and focused on layering.
        require_vendor phpstan
        exec vendor/bin/phpstan analyse --level=0 --memory-limit=1G --no-progress Classes
        ;;
    mutation)
        require_vendor infection
        exec vendor/bin/infection --threads=4 --no-progress
        ;;
    ci)
        require_vendor php-cs-fixer
        require_vendor phpstan
        require_vendor phpunit
        lint_php_files
        vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
        vendor/bin/phpstan analyse --memory-limit=1G --no-progress
        vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
        vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
        exec vendor/bin/phpstan analyse --level=0 --memory-limit=1G --no-progress Classes
        ;;
    *)
        echo "Unknown suite: $suite (valid: lint, cs, phpstan, unit, functional, architecture, mutation, ci)" >&2
        exit 2
        ;;
esac
