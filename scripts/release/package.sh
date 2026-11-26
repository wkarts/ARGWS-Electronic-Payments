#!/usr/bin/env bash
set -euo pipefail

tag=${1:?Informe a tag estável}
[[ $tag =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || exit 2
root=$(git rev-parse --show-toplevel)
sha=$(git rev-parse HEAD)
dest=$(realpath -m "${2:-$root/dist}")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$dest" "$work/electronic-payments" "$work/check"

# Instalar em uma pasta nova garante que o ZIP use exclusivamente o lock do commit.
git -C "$root" archive HEAD README.md composer.json composer.lock src tests | tar -x -C "$work/electronic-payments"
cd "$work/electronic-payments"
export COMPOSER_ROOT_VERSION="${tag#v}"
composer validate --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
jq -n --arg tag "$tag" --arg sha "$sha" \
  '{schema_version: 1, version: ($tag | ltrimstr("v")), tag: $tag, git_commit_sha: $sha, release_channel: "stable"}' > release-info.json

file="argws-electronic-payments-${tag}.zip"
cd "$work"
zip -qr "$file" electronic-payments -x '*/.git' '*/.git/*'
unzip -q "$file" -d check
cd check/electronic-payments
php -r 'require "vendor/autoload.php"; if (!class_exists(Asaas\Sdk\AsaasSdk::class) || !class_exists(Argws\ElectronicPayments\Asaas\AsaasProvider::class)) exit(1);'
php tests/run.php
cd "$work"
sha256sum "$file" > "$file.sha256"
mv "$file" "$file.sha256" "$dest/"
