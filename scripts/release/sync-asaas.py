"""Bundle the newest stable Asaas SDK from a local checkout of the official repository."""
import argparse
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
NAME = "argws/asaas-sdk-php"
BUNDLE = ROOT / "vendor" / NAME
STABLE = re.compile(r"v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)")


def git(repo, *args):
    return subprocess.check_output(["git", "-C", repo, *args], text=True).strip()


def sync(repo, check):
    manifest = ROOT / "composer.json"
    package = json.loads(manifest.read_text())
    tags = [match for line in git(repo, "tag", "--list").splitlines() if (match := STABLE.fullmatch(line))]
    if not tags:
        raise SystemExit("A origem não possui tags SemVer estáveis.")
    tag = "v" + package["replace"][NAME] if check else max(tags, key=lambda m: tuple(map(int, m.groups())))[0]
    sha = git(repo, "rev-parse", "--verify", tag + "^{commit}")
    pinned = package["extra"]["bundled-sdks"][NAME]["reference"]
    if tag[1:] == package["replace"][NAME] and sha != pinned:
        raise SystemExit("A tag da SDK mudou de commit. Confira a origem.")
    upstream = json.loads(git(repo, "show", sha + ":composer.json"))
    previous = json.loads((BUNDLE / "composer.json").read_text())
    if upstream["name"] != NAME or any(upstream.get(k) != previous.get(k) for k in ("require", "autoload", "conflict", "provide", "replace")):
        raise SystemExit("O contrato Composer da SDK mudou. Compatibilize os requisitos e o autoload do pacote antes da publicação.")
    # Exportar a árvore Git inclui a licença e os recursos usados pela SDK, sem metadados .git.
    with tempfile.TemporaryDirectory() as tmp:
        tree = Path(tmp) / "sdk"
        git(repo, "worktree", "add", "--quiet", "--detach", tree, sha)
        try:
            (tree / ".git").unlink()
            if check:
                result = subprocess.run(["git", "diff", "--no-index", "--quiet", BUNDLE, tree])
                if result.returncode:
                    raise SystemExit("A SDK instalada difere da árvore da tag " + tag)
            else:
                shutil.copytree(tree, Path(tmp) / "bundle", symlinks=True)
                shutil.rmtree(BUNDLE)
                shutil.move(Path(tmp) / "bundle", BUNDLE)
                package["replace"][NAME] = tag[1:]
                package["extra"]["bundled-sdks"][NAME]["reference"] = sha
                manifest.write_text(json.dumps(package, ensure_ascii=False, indent=4) + "\n")
        finally:
            shutil.rmtree(tree)
            git(repo, "worktree", "prune")
    print(tag)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("repository", type=Path)
    parser.add_argument("--check", action="store_true", help="Verify the exact tag pinned in composer.json")
    args = parser.parse_args()
    sync(args.repository.resolve(), args.check)
