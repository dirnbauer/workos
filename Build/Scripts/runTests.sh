#!/usr/bin/env bash
#
# Runs the extension's quality checks uniformly on a developer machine
# or a CI runner. Pick a suite with `-s`; default is `unit`.
#
# Usage:
#   Build/Scripts/runTests.sh                 # unit tests
#   Build/Scripts/runTests.sh -s lint         # php -l on every extension PHP file
#   Build/Scripts/runTests.sh -s cs           # TYPO3 coding standards (dry-run)
#   Build/Scripts/runTests.sh -s phpstan      # PHPStan level 8 incl. phpat layering rules
#   Build/Scripts/runTests.sh -s unit         # PHPUnit unit suite
#   Build/Scripts/runTests.sh -s functional   # PHPUnit functional suite (needs typo3Database* env)
#   Build/Scripts/runTests.sh -s mutation     # Infection mutation testing
#   Build/Scripts/runTests.sh -s ci           # lint + cs + phpstan + unit + functional
#
# Local functional run without a database server:
#   typo3DatabaseDriver=pdo_sqlite Build/Scripts/runTests.sh -s functional

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

BIN=".Build/bin"
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

require_bin() {
    if [[ ! -x "$BIN/$1" ]]; then
        echo "$BIN/$1 is missing. Run 'composer install' first." >&2
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
        require_bin php-cs-fixer
        exec "$BIN/php-cs-fixer" fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
        ;;
    phpstan)
        require_bin phpstan
        exec "$BIN/phpstan" analyse --memory-limit=1G --no-progress
        ;;
    unit)
        require_bin phpunit
        exec "$BIN/phpunit" -c Build/phpunit/UnitTests.xml
        ;;
    functional)
        require_bin phpunit
        exec "$BIN/phpunit" -c Build/phpunit/FunctionalTests.xml
        ;;
    mutation)
        require_bin infection
        exec "$BIN/infection" --threads=4 --no-progress
        ;;
    ci)
        require_bin php-cs-fixer
        require_bin phpstan
        require_bin phpunit
        lint_php_files
        "$BIN/php-cs-fixer" fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no
        "$BIN/phpstan" analyse --memory-limit=1G --no-progress
        "$BIN/phpunit" -c Build/phpunit/UnitTests.xml
        exec "$BIN/phpunit" -c Build/phpunit/FunctionalTests.xml
        ;;
    *)
        echo "Unknown suite: $suite (valid: lint, cs, phpstan, unit, functional, mutation, ci)" >&2
        exit 2
        ;;
esac
