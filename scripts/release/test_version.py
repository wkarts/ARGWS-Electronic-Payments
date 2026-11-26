import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("version.py").resolve()


class StableVersionTest(unittest.TestCase):
    def setUp(self):
        working = tempfile.TemporaryDirectory()
        self.addCleanup(working.cleanup)
        self.repo = working.name
        self.git("init", "-q", "-b", "main")
        self.git("config", "user.name", "Release Test")
        self.git("config", "user.email", "release@example.invalid")
        self.commit("Inicial")

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.repo, text=True).strip()

    def commit(self, message):
        self.git("commit", "-q", "--allow-empty", "-m", message)
        self.git("update-ref", "refs/remotes/origin/main", "HEAD")

    def version(self, *args):
        return subprocess.check_output([sys.executable, str(SCRIPT), *args], cwd=self.repo, text=True).strip()

    def test_stable_progression_and_retry(self):
        self.assertEqual(self.version(), "v0.1.0")
        self.git("tag", "-a", "v0.1.0", "-m", "Inicial")
        self.assertEqual(self.version(), "v0.1.0")
        self.commit("Correção")
        self.git("tag", "v7.0.0-rc.1")
        self.assertEqual(self.version(), "v0.1.1")
        self.git("tag", "v0.1.1")
        self.commit("Nova operação\n\nRelease-Bump: minor")
        self.commit("Merge")
        self.assertEqual(self.version(), "v0.2.0")
        self.commit("Novo contrato\n\nRelease-Bump: major")
        self.assertEqual(self.version(), "v1.0.0")

    def test_tag_event_and_superseded_ci(self):
        self.commit("Commit atrasado")
        older = self.git("rev-parse", "HEAD")
        self.commit("Commit já publicado")
        self.git("tag", "v1.3.5")
        self.assertEqual(self.version("v1.3.5"), "v1.3.5")
        self.git("checkout", "-q", older)
        self.assertEqual(self.version(), "")
        for value in ("v1.3.5", "v01.3.5", "v1.0.0-beta"):
            self.assertNotEqual(subprocess.run([sys.executable, str(SCRIPT), value], cwd=self.repo,
                                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode, 0)

    def test_branch_outside_main(self):
        self.git("switch", "-qc", "feature")
        self.git("commit", "-q", "--allow-empty", "-m", "Alteração em revisão")
        result = subprocess.run([sys.executable, str(SCRIPT)], cwd=self.repo, capture_output=True)
        self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
