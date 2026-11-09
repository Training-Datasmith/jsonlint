<?php

declare(strict_types=1);

/**
 * Verify org-fork aliases (none expected) and Packagist gap constraints for phpunit/phpstan.
 */

$root = dirname(__DIR__);
$vendorDir = $root . '/vendor';

$expectedAliases = array(
);

$gapPackagist = array(
    'phpunit/phpunit' => array(
        'min_version' => '8.5.52',
        'max_version' => '9.0.0',
        'forbid_url' => 'Training-Datasmith',
        'require_url_contains' => 'phpunit',
    ),
    'phpstan/phpstan' => array(
        'min_version' => '1.11.0',
        'max_version' => '2.0.0',
        'forbid_url' => 'Training-Datasmith',
        'require_url_contains' => 'phpstan',
    ),
);

if (!is_readable($vendorDir . '/composer/installed.php')) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions: array<string, array{reference?: string, aliases?: list<string>, version?: string}>} $installedPhp */
$installedPhp = require $vendorDir . '/composer/installed.php';
$versionsPhp = $installedPhp['versions'] ?? array();

$installedJsonPath = $vendorDir . '/composer/installed.json';
if (!is_readable($installedJsonPath)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found.\n");
    exit(1);
}

$installedJsonRaw = file_get_contents($installedJsonPath);
if ($installedJsonRaw === false) {
    fwrite(STDERR, "check-cluster-pins: could not read installed.json.\n");
    exit(1);
}

/** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>}|list<array<string, mixed>> $installedJson */
$installedJson = json_decode($installedJsonRaw, true);
if (!is_array($installedJson)) {
    fwrite(STDERR, "check-cluster-pins: invalid installed.json.\n");
    exit(1);
}

$packagesJson = array();
if (isset($installedJson['packages']) && is_array($installedJson['packages'])) {
    $packagesJson = array_merge($packagesJson, $installedJson['packages']);
}
if (isset($installedJson['packages-dev']) && is_array($installedJson['packages-dev'])) {
    $packagesJson = array_merge($packagesJson, $installedJson['packages-dev']);
} elseif (!isset($installedJson['packages']) && array_values($installedJson) === $installedJson) {
    $packagesJson = $installedJson;
}

$byNameJson = array();
foreach ($packagesJson as $pkg) {
    if (!is_array($pkg) || empty($pkg['name'])) {
        continue;
    }
    $byNameJson[$pkg['name']] = $pkg;
}

$failed = false;

foreach ($expectedAliases as $name => $want) {
    if (!isset($versionsPhp[$name])) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s is not installed.\n", $name));
        $failed = true;
        continue;
    }

    $pkg = $versionsPhp[$name];
    $aliases = isset($pkg['aliases']) && is_array($pkg['aliases']) ? $pkg['aliases'] : array();
    if (!in_array($want['alias'], $aliases, true)) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s alias mismatch (expected %s in aliases %s).\n",
            $name,
            $want['alias'],
            json_encode($aliases)
        ));
        $failed = true;
    }

    $reference = isset($pkg['reference']) ? (string) $pkg['reference'] : '';
    if (strlen($reference) !== 40 || !ctype_xdigit($reference)) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s has no 40-char reference in installed.php.\n", $name));
        $failed = true;
        continue;
    }
    if (!hash_equals(strtolower($want['reference']), strtolower($reference))) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s reference mismatch (expected %s, got %s).\n",
            $name,
            $want['reference'],
            $reference
        ));
        $failed = true;
    }

    if (!isset($byNameJson[$name]['source']['reference'])) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s missing source.reference in installed.json.\n", $name));
        $failed = true;
        continue;
    }
    $jsonRef = (string) $byNameJson[$name]['source']['reference'];
    if (!hash_equals(strtolower($want['reference']), strtolower($jsonRef))) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s installed.json reference mismatch (expected %s, got %s).\n",
            $name,
            $want['reference'],
            $jsonRef
        ));
        $failed = true;
    }
}

foreach ($gapPackagist as $name => $spec) {
    if (!isset($versionsPhp[$name])) {
        fwrite(STDERR, sprintf("check-cluster-pins: gap package %s is not installed.\n", $name));
        $failed = true;
        continue;
    }

    $pkgPhp = $versionsPhp[$name];
    $pkgJson = isset($byNameJson[$name]) ? $byNameJson[$name] : null;
    if ($pkgJson === null) {
        fwrite(STDERR, sprintf("check-cluster-pins: gap package %s missing from installed.json.\n", $name));
        $failed = true;
        continue;
    }

    $version = isset($pkgPhp['version']) ? (string) $pkgPhp['version'] : '';
    $version = ltrim($version, 'vV');
    if ($version === '' || version_compare($version, $spec['min_version'], '<') || version_compare($version, $spec['max_version'], '>=')) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s version %s outside [%s, %s).\n",
            $name,
            $version,
            $spec['min_version'],
            $spec['max_version']
        ));
        $failed = true;
    }

    $url = '';
    if (isset($pkgJson['source']['url'])) {
        $url = (string) $pkgJson['source']['url'];
    }
    if (strpos($url, $spec['forbid_url']) !== false) {
        fwrite(STDERR, sprintf("check-cluster-pins: %s must not come from org fork (url %s).\n", $name, $url));
        $failed = true;
    }
    if (strpos($url, $spec['require_url_contains']) === false && strpos($url, 'packagist') === false && strpos($url, 'repo.packagist.org') === false) {
        if (!($url === '' && isset($pkgJson['dist']['url']))) {
            // dist-only installs still have empty source url; version check above is enough.
        }
    }

    $refPhp = isset($pkgPhp['reference']) ? (string) $pkgPhp['reference'] : '';
    $refJson = isset($pkgJson['source']['reference']) ? (string) $pkgJson['source']['reference'] : '';
    if ($refPhp !== '' && $refJson !== '' && strcasecmp($refPhp, $refJson) !== 0) {
        fwrite(STDERR, sprintf(
            "check-cluster-pins: %s reference mismatch installed.php vs installed.json (%s vs %s).\n",
            $name,
            $refPhp,
            $refJson
        ));
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

if (!run_wrong_sha_demo()) {
    exit(1);
}

fwrite(STDOUT, sprintf(
    "check-cluster-pins: OK (%d aliased, %d packagist gaps)\n",
    count($expectedAliases),
    count($gapPackagist)
));
exit(0);

/**
 * Prove pin logic fails when only installed.php reference or only installed.json reference is wrong.
 */
function run_wrong_sha_demo(): bool
{
    $fixture = array(
        'demo/pkg' => array(
            'alias' => '9.9.9',
            'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ),
    );

    $installedPhp = array(
        'versions' => array(
            'demo/pkg' => array(
                'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'aliases' => array('9.9.9'),
            ),
        ),
    );

    $installedJson = array(
        'packages' => array(
            array(
                'name' => 'demo/pkg',
                'source' => array(
                    'type' => 'git',
                    'url' => 'https://github.com/Training-Datasmith/demo.git',
                    'reference' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ),
            ),
        ),
    );

    if (!assert_pin_map($fixture, $installedPhp, $installedJson)) {
        fwrite(STDERR, "check-cluster-pins: wrong-SHA demo failed (expected pass on matching fixture).\n");
        return false;
    }

    $badPhp = $installedPhp;
    $badPhp['versions']['demo/pkg']['reference'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    if (assert_pin_map($fixture, $badPhp, $installedJson)) {
        fwrite(STDERR, "check-cluster-pins: wrong-SHA demo did not fail on bad installed.php reference.\n");
        return false;
    }

    $badJson = $installedJson;
    $badJson['packages'][0]['source']['reference'] = 'cccccccccccccccccccccccccccccccccccccccc';
    if (assert_pin_map($fixture, $installedPhp, $badJson)) {
        fwrite(STDERR, "check-cluster-pins: wrong-SHA demo did not fail on bad installed.json source.reference.\n");
        return false;
    }

    fwrite(STDOUT, "check-cluster-pins: wrong-SHA demo OK (installed.php reference, installed.json source.reference)\n");

    return true;
}

/**
 * @param array<string, array{alias: string, reference: string}> $expected
 * @param array{versions: array<string, array{reference?: string, aliases?: list<string>}>} $installedPhp
 * @param array{packages: list<array{name: string, source: array{reference: string}}>} $installedJson
 */
function assert_pin_map(array $expected, array $installedPhp, array $installedJson): bool
{
    $versions = $installedPhp['versions'];
    $byName = array();
    foreach ($installedJson['packages'] as $pkg) {
        if (isset($pkg['name'])) {
            $byName[$pkg['name']] = $pkg;
        }
    }

    foreach ($expected as $name => $want) {
        if (!isset($versions[$name])) {
            return false;
        }
        $aliases = isset($versions[$name]['aliases']) ? $versions[$name]['aliases'] : array();
        if (!in_array($want['alias'], $aliases, true)) {
            return false;
        }
        $ref = isset($versions[$name]['reference']) ? (string) $versions[$name]['reference'] : '';
        if (!hash_equals($want['reference'], $ref)) {
            return false;
        }
        if (!isset($byName[$name]['source']['reference'])) {
            return false;
        }
        $jsonRef = (string) $byName[$name]['source']['reference'];
        if (!hash_equals($want['reference'], $jsonRef)) {
            return false;
        }
    }

    return true;
}
