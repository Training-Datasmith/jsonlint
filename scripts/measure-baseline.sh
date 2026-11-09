#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
DOCKER=(docker)
if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
  DOCKER=(sudo docker)
fi

PHP_IMAGE='php:7.2-cli@sha256:42ffbc0798e4449bbd1e14fc4dcb87774aa1ad1900a09ef6a965bc0880aa2161'
COMPOSER_URL='https://getcomposer.org/download/2.8.10/composer.phar'
COMPOSER_SHA256='28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573'

mkdir -p "$ROOT/.system-test/baseline-tree/src/Seld/JsonLint" "$ROOT/.system-test/baseline-tree/tests"

for f in DuplicateKeyException.php JsonParser.php Lexer.php ParsingException.php Undefined.php; do
  git show "origin/main:src/Seld/JsonLint/${f}" > "$ROOT/.system-test/baseline-tree/src/Seld/JsonLint/${f}"
done
git show origin/main:tests/JsonParserTest.php > "$ROOT/.system-test/baseline-tree/tests/JsonParserTest.php"
git show origin/main:tests/bom.json > "$ROOT/.system-test/baseline-tree/tests/bom.json"
git show origin/main:tests/with-comments.json > "$ROOT/.system-test/baseline-tree/tests/with-comments.json"
git show origin/main:tests/without-comments.json > "$ROOT/.system-test/baseline-tree/tests/without-comments.json"

cat > "$ROOT/.system-test/baseline-tree/composer.json" <<'JSON'
{
    "require": { "php": "^7.2" },
    "require-dev": { "phpunit/phpunit": "^8.5.52" },
    "autoload": { "psr-4": { "Seld\\JsonLint\\": "src/Seld/JsonLint/" } }
}
JSON

cat > "$ROOT/.system-test/baseline-tree/phpunit.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit colors="false" bootstrap="tests/bootstrap.php">
    <testsuites>
        <testsuite name="baseline">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML

cat > "$ROOT/.system-test/baseline-tree/tests/bootstrap.php" <<'PHP'
<?php
require __DIR__ . '/../vendor/autoload.php';
PHP

read -r -d '' INNER <<EOS || true
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
if grep -q buster /etc/apt/sources.list 2>/dev/null; then
  sed -i 's|deb.debian.org/debian|archive.debian.org/debian|g; s|security.debian.org/debian-security|archive.debian.org/debian-security|g' /etc/apt/sources.list
  echo 'Acquire::Check-Valid-Until "false";' >/etc/apt/apt.conf.d/99no-check-valid
fi
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends git unzip ca-certificates curl
rm -rf /var/lib/apt/lists/*
curl -fsSL -o /tmp/composer.phar '${COMPOSER_URL}'
echo '${COMPOSER_SHA256}  /tmp/composer.phar' | sha256sum -c -
cd /baseline
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress
vendor/bin/phpunit --colors=never --do-not-cache-result | tee /baseline/phpunit.out
EOS

"${DOCKER[@]}" pull "$PHP_IMAGE"
"${DOCKER[@]}" run --rm \
  -v "$ROOT/.system-test/baseline-tree:/baseline" \
  "$PHP_IMAGE" bash -lc "$INNER"

python3 <<'PY'
import json, re, shutil
from pathlib import Path
root = Path('/workspace')
out = (root / '.system-test/baseline-tree/phpunit.out').read_text()
m = re.search(r'^Tests: (\d+), Assertions: (\d+)(?:, [^\.]+)?\.$', out, re.M)
if not m:
    raise SystemExit('Could not parse phpunit baseline output:\n' + out[-3000:])
baseline_path = root / 'scripts/phpunit-baseline.json'
baseline = json.loads(baseline_path.read_text())
baseline['tests'] = int(m.group(1))
baseline['assertions'] = int(m.group(2))
baseline_path.write_text(json.dumps(baseline, indent=4) + '\n')
print('Committed baseline: tests=%s assertions=%s' % (baseline['tests'], baseline['assertions']))
PY
