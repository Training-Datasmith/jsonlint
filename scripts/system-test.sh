#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
DOCKER=(docker)
if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
  DOCKER=(sudo docker)
fi

PHP_TEST_IMAGE='php:7.2-cli@sha256:42ffbc0798e4449bbd1e14fc4dcb87774aa1ad1900a09ef6a965bc0880aa2161'
DEBIAN_FLOOR_IMAGE='debian:stretch@sha256:c5c5200ff1e9c73ffbf188b4a67eb1c91531b644856b4aefe86a58d2f0cb05be'
COMPOSER_URL='https://getcomposer.org/download/2.8.10/composer.phar'
COMPOSER_SHA256='28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573'
PHP53_TAR_URL='https://www.php.net/distributions/php-5.3.29.tar.gz'
PHP53_TAR_SHA256='57cf097de3d6c3152dda342f62b1b2e9c988f4cfe300ccfe3c11f3c207a0e317'

mkdir -p "$ROOT/.system-test"

strip_ansi() {
  sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g'
}

reject_noise() {
  local label="$1"
  local file="$2"
  local stripped
  stripped="$(strip_ansi < "$file")"
  if grep -E '^(PHP )?(Warning|Deprecated|Notice):' <<<"$stripped" >/dev/null; then
    echo "${label}: contains Warning/Deprecated/Notice" >&2
    grep -E '^(PHP )?(Warning|Deprecated|Notice):' <<<"$stripped" >&2 || true
    return 1
  fi
  return 0
}

gate_line() {
  local file="$1"
  local regex="$2"
  local stripped
  stripped="$(strip_ansi < "$file")"
  if ! grep -Eq "$regex" <<<"$stripped"; then
    echo "gate failed: expected /${regex}/ in ${file}" >&2
    return 1
  fi
  grep -E "$regex" <<<"$stripped" | tail -n1
}

read -r -d '' TEST_INNER <<'EOS' || true
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
export DEBIAN_FRONTEND=noninteractive
echo 'Acquire::Check-Valid-Until "false";' >/etc/apt/apt.conf.d/99no-check-valid
if grep -qE '^VERSION_CODENAME=buster' /etc/os-release 2>/dev/null; then
  cat >/etc/apt/sources.list <<'APT'
deb http://archive.debian.org/debian buster main
deb http://archive.debian.org/debian-security buster/updates main
deb http://archive.debian.org/debian buster-updates main
APT
elif grep -qE '^VERSION_CODENAME=stretch' /etc/os-release 2>/dev/null; then
  cat >/etc/apt/sources.list <<'APT'
deb http://archive.debian.org/debian stretch main
deb http://archive.debian.org/debian-security stretch/updates main
APT
else
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates apt-transport-https
fi
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates apt-transport-https
if grep -qE '^VERSION_CODENAME=buster' /etc/os-release 2>/dev/null; then
  cat >/etc/apt/sources.list <<'APT'
deb https://archive.debian.org/debian buster main
deb https://archive.debian.org/debian-security buster/updates main
deb https://archive.debian.org/debian buster-updates main
APT
elif grep -qE '^VERSION_CODENAME=stretch' /etc/os-release 2>/dev/null; then
  cat >/etc/apt/sources.list <<'APT'
deb https://archive.debian.org/debian stretch main
deb https://archive.debian.org/debian-security stretch/updates main
APT
fi
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends git unzip curl
rm -rf /var/lib/apt/lists/*
curl -fsSL -o /tmp/composer.phar "$COMPOSER_URL"
echo "$COMPOSER_SHA256  /tmp/composer.phar" | sha256sum -c -
php -v | tee /workspace/.system-test/php-version.txt
git config --global --add safe.directory /workspace || true
cd /workspace
php /tmp/composer.phar --version | tee /workspace/.system-test/composer-version.txt
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress 2>&1 | tee /workspace/.system-test/composer-update.txt
php /tmp/composer.phar audit 2>&1 | tee /workspace/.system-test/composer-audit.txt
php scripts/check-cluster-pins.php 2>&1 | tee /workspace/.system-test/check-cluster-pins.txt
php scripts/check-rename.php 2>&1 | tee /workspace/.system-test/check-rename.txt
vendor/bin/phpunit --colors=never --do-not-cache-result --verbose 2>&1 | tee /workspace/.system-test/phpunit.txt
php scripts/check-phpunit-gate.php /workspace/.system-test/phpunit.txt 2>&1 | tee /workspace/.system-test/phpunit-gate.txt
vendor/bin/phpstan analyse --memory-limit=1G --no-progress --no-ansi 2>&1 | tee /workspace/.system-test/phpstan.txt
EOS

read -r -d '' FLOOR_INNER <<'EOS' || true
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
cat >/etc/apt/sources.list <<'APT'
deb http://archive.debian.org/debian stretch main
deb http://archive.debian.org/debian-security stretch/updates main
APT
cat >/etc/apt/apt.conf.d/99no-check-valid <<'APT'
Acquire::Check-Valid-Until "false";
APT
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
  ca-certificates apt-transport-https curl
cat >/etc/apt/sources.list <<'APT'
deb https://archive.debian.org/debian stretch main
deb https://archive.debian.org/debian-security stretch/updates main
APT
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
  build-essential libxml2-dev libssl1.0-dev pkg-config bison re2c
rm -rf /var/lib/apt/lists/*
curl -fsSL -o /tmp/php-5.3.29.tar.gz "$PHP53_TAR_URL"
echo "$PHP53_TAR_SHA256  /tmp/php-5.3.29.tar.gz" | sha256sum -c -
cd /usr/local/src
tar -xzf /tmp/php-5.3.29.tar.gz
cd php-5.3.29
./configure --prefix=/usr/local/php53 --disable-all --enable-cli
make -j2
make install
/usr/local/php53/bin/php -v | tee /workspace/.system-test/php53-version.txt
files=(
  src/Seld/Json_Lint/Duplicate_Key_Exception.php
  src/Seld/Json_Lint/Json_Parser.php
  src/Seld/Json_Lint/Lexer.php
  src/Seld/Json_Lint/Parsing_Exception.php
  src/Seld/Json_Lint/Undefined.php
)
has_errors=0
: > /workspace/.system-test/php-lint.txt
for f in "${files[@]}"; do
  out="$(/usr/local/php53/bin/php -l "/workspace/${f}" 2>&1)"
  printf '%s\n' "$out" >> /workspace/.system-test/php-lint.txt
  if ! grep -Eq '^No syntax errors detected in /workspace/.+\.php$' <<<"$out"; then
    has_errors=1
  fi
done
if [[ "$has_errors" -ne 0 ]]; then
  exit 1
fi
echo "php-lint: OK (5 files)" | tee -a /workspace/.system-test/php-lint.txt
EOS

COMPOSER_URL_ESC="$COMPOSER_URL"
COMPOSER_SHA_ESC="$COMPOSER_SHA256"
PHP53_URL_ESC="$PHP53_TAR_URL"
PHP53_SHA_ESC="$PHP53_TAR_SHA256"

TEST_INNER="${TEST_INNER//\$COMPOSER_URL/$COMPOSER_URL_ESC}"
TEST_INNER="${TEST_INNER//\$COMPOSER_SHA256/$COMPOSER_SHA_ESC}"
FLOOR_INNER="${FLOOR_INNER//\$PHP53_TAR_URL/$PHP53_URL_ESC}"
FLOOR_INNER="${FLOOR_INNER//\$PHP53_TAR_SHA256/$PHP53_SHA_ESC}"

"${DOCKER[@]}" pull "$PHP_TEST_IMAGE"
"${DOCKER[@]}" pull "$DEBIAN_FLOOR_IMAGE"

"${DOCKER[@]}" run --rm -v "$ROOT:/workspace" -w /workspace \
  -e COMPOSER_URL="$COMPOSER_URL" -e COMPOSER_SHA256="$COMPOSER_SHA256" \
  "$PHP_TEST_IMAGE" bash -lc "$TEST_INNER"

"${DOCKER[@]}" run --rm -v "$ROOT:/workspace" -w /workspace \
  -e PHP53_TAR_URL="$PHP53_TAR_URL" -e PHP53_TAR_SHA256="$PHP53_TAR_SHA256" \
  "$DEBIAN_FLOOR_IMAGE" bash -lc "$FLOOR_INNER"

reject_noise composer-update "$ROOT/.system-test/composer-update.txt"
reject_noise composer-audit "$ROOT/.system-test/composer-audit.txt"
reject_noise phpunit "$ROOT/.system-test/phpunit.txt"
reject_noise phpstan "$ROOT/.system-test/phpstan.txt"

gate_line "$ROOT/.system-test/php-version.txt" '^PHP 7\.2\.'
gate_line "$ROOT/.system-test/composer-version.txt" '^Composer version 2\.8\.10 '
gate_line "$ROOT/.system-test/composer-audit.txt" '^No security vulnerability advisories found\.$'
gate_line "$ROOT/.system-test/check-cluster-pins.txt" '^check-cluster-pins: wrong-SHA demo OK \(installed\.php reference, installed\.json source\.reference\)$'
gate_line "$ROOT/.system-test/check-cluster-pins.txt" '^check-cluster-pins: OK \(0 aliased, 2 packagist gaps\)$'
gate_line "$ROOT/.system-test/check-rename.txt" '^check-rename: OK \([0-9]+ files\)\.$'
gate_line "$ROOT/.system-test/php53-version.txt" '^PHP 5\.3\.29 \(cli\)'
gate_line "$ROOT/.system-test/php-lint.txt" '^php-lint: OK \(5 files\)$'
gate_line "$ROOT/.system-test/phpstan.txt" '^ \[OK\] No errors\s*$'

gate_line "$ROOT/.system-test/phpunit-gate.txt" '^phpunit-gate: OK$'

echo 'system-test: all gates passed.'
cat "$ROOT/.system-test/gate-summary.txt" 2>/dev/null || {
  {
    gate_line "$ROOT/.system-test/php-version.txt" '^PHP 7\.2\.'
    gate_line "$ROOT/.system-test/composer-version.txt" '^Composer version 2\.8\.10 '
    gate_line "$ROOT/.system-test/composer-audit.txt" '^No security vulnerability advisories found\.$'
    gate_line "$ROOT/.system-test/check-cluster-pins.txt" '^check-cluster-pins: wrong-SHA demo OK \(installed\.php reference, installed\.json source\.reference\)$'
    gate_line "$ROOT/.system-test/check-cluster-pins.txt" '^check-cluster-pins: OK \(0 aliased, 2 packagist gaps\)$'
    gate_line "$ROOT/.system-test/check-rename.txt" '^check-rename: OK \([0-9]+ files\)\.$'
    strip_ansi < "$ROOT/.system-test/phpunit.txt" | grep -E '^OK, but incomplete, skipped, or risky tests!$' || true
    strip_ansi < "$ROOT/.system-test/phpunit.txt" | grep -E '^Tests: [0-9]+, Assertions: [0-9]+(, Skipped: [0-9]+)?\.$' || true
    gate_line "$ROOT/.system-test/phpstan.txt" '^ \[OK\] No errors\s*$'
    gate_line "$ROOT/.system-test/php53-version.txt" '^PHP 5\.3\.29 \(cli\)'
    gate_line "$ROOT/.system-test/php-lint.txt" '^php-lint: OK \(5 files\)$'
    gate_line "$ROOT/.system-test/phpunit-gate.txt" '^phpunit-gate: OK$'
  } | tee "$ROOT/.system-test/gate-summary.txt"
}
