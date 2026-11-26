"""Select a stable SemVer tag for the checked-out commit. Never mutate Git refs."""
import re
import subprocess
import sys

STABLE = re.compile(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)")


def git(*args):
    return subprocess.check_output(["git", *args], text=True).strip()


def versions(*args):
    tags = git("tag", "--list", *args).splitlines()
    return sorted(tuple(map(int, match.groups())) for tag in tags if (match := STABLE.fullmatch(tag)))


def tag(version):
    return "v%d.%d.%d" % version


def select(explicit):
    head = git("rev-parse", "HEAD")
    if subprocess.run(["git", "merge-base", "--is-ancestor", head, "origin/main"]).returncode:
        raise ValueError("O commit da release precisa pertencer à main.")
    if explicit:
        if not STABLE.fullmatch(explicit) or git("rev-parse", f"refs/tags/{explicit}^{{commit}}") != head:
            raise ValueError("A tag deve ser vMAJOR.MINOR.PATCH e apontar para o commit validado.")
        return explicit

    same = versions("--points-at", head)
    if same:
        return tag(same[-1])
    if versions("--contains", head):
        return ""  # Um commit posterior já recebeu uma versão estável.

    previous = versions("--merged", head)
    if not previous:
        return "v0.1.0"
    major, minor, patch = previous[-1]
    # Trailers também funcionam em merges tradicionais, onde o HEAD tem mensagem gerada.
    flags = git("log", "--format=%(trailers:key=Release-Bump,valueonly)", f"{tag(previous[-1])}..HEAD").lower().split()
    if set(flags) - {"major", "minor", "patch"}:
        raise ValueError("Release-Bump deve ser major, minor ou patch.")
    if "major" in flags:
        return tag((major + 1, 0, 0))
    if "minor" in flags:
        return tag((major, minor + 1, 0))
    return tag((major, minor, patch + 1))


if __name__ == "__main__":
    try:
        print(select(sys.argv[1] if len(sys.argv) > 1 else ""))
    except ValueError as error:
        sys.exit(str(error))
