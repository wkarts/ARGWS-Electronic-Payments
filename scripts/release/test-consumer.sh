#!/usr/bin/env bash
set -euo pipefail
root=$(git rev-parse --show-toplevel)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
# Composer copie a distribuição como faria em um projeto consumidor.
jq -n --arg repo "$root" '{repositories:[{type:"path", url:$repo, options:{symlink:false, versions:{"argws/electronic-payments":"1.0.0"}}}],require:{"argws/electronic-payments":"1.0.0"}}' > "$work/composer.json"
cd "$work"
composer install --no-interaction --no-progress --no-plugins --no-scripts --classmap-authoritative
php -r '
require "vendor/autoload.php";
$base = "vendor/argws/electronic-payments/vendor/argws/asaas-sdk-php";
$loaded = (new ReflectionClass(Asaas\Sdk\AsaasSdk::class))->getFileName();
if (is_dir("vendor/argws/asaas-sdk-php") || realpath("$base/src/AsaasSdk.php") !== $loaded) exit(1);
if (!class_exists(Argws\ElectronicPayments\Asaas\AsaasProvider::class)) exit(1);
foreach (json_decode(file_get_contents("composer.lock"), true)["packages"] as $p) {
    if ($p["name"] === "argws/asaas-sdk-php") exit(1);
}
echo "SDK única carregada de $loaded\n";
'
