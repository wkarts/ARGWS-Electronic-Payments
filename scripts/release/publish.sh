#!/usr/bin/env bash
set -euo pipefail

tag=${1:?Informe a tag validada}
sha=$(git rev-parse HEAD)
zip="argws-electronic-payments-${tag}.zip"
(cd dist && sha256sum -c "$zip.sha256")

# Push sem force também protege contra uma tag criada enquanto o ZIP era gerado.
if ! git show-ref --verify --quiet "refs/tags/$tag"; then
  git tag "$tag" "$sha"
fi
[[ $(git rev-parse "refs/tags/$tag^{commit}") == "$sha" ]]
git push origin "refs/tags/$tag:refs/tags/$tag"

if ! gh release view "$tag" --json isDraft > /dev/null 2>&1; then
  gh release create "$tag" --draft --verify-tag --generate-notes --title "$tag"
fi
state=$(gh release view "$tag" --json isDraft,assets)
if [[ $(jq -r .isDraft <<< "$state") == true ]]; then
  gh release upload "$tag" "dist/$zip" "dist/$zip.sha256" --clobber
  gh release edit "$tag" --draft=false
else
  # Uma release concluída é imutável. Reexecuções podem repetir a notificação Packagist.
  jq -e --arg zip "$zip" '([.assets[].name] | index($zip) != null and index($zip + ".sha256") != null)' <<< "$state" > /dev/null || {
    echo "A release publicada já existe com ativos incompletos." >&2
    exit 1
  }
fi
