import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "deploy"))
from setup import Project


class ProjectSetupTest(unittest.TestCase):
    def setUp(self):
        self.project = Project("assessment-fixture", "europe-west1")
        self.project.cli = Mock()

    def test_redeploy_reuses_secret_without_reading_or_rotating_it(self):
        supplier = Mock()
        self.project.cli.return_value = "ENABLED"
        self.project.ensure_secret("existing", supplier, {"existing"})
        supplier.assert_not_called()
        self.assertEqual(self.project.cli.call_count, 1)
        self.assertEqual(self.project.cli.call_args.args[:3], ("secrets", "versions", "list"))

    def test_disabled_version_stops_deployment_without_replacing_credentials(self):
        self.project.cli.return_value = "DISABLED"
        with self.assertRaisesRegex(SystemExit, "DISABLED"):
            self.project.ensure_secret("existing", lambda: self.fail("unexpected rotation"), {"existing"})

    def test_new_secret_uses_standard_input(self):
        self.project.ensure_secret("new", lambda: "fixture-only", set())
        args, kwargs = self.project.cli.call_args
        self.assertNotIn("fixture-only", args)
        self.assertIn("--data-file=-", args)
        self.assertEqual(kwargs["data"], "fixture-only")

    def test_missing_provider_credentials_stop_before_paid_instance_creation(self):
        self.project.names = Mock(return_value=set())
        with patch("sys.stdin.isatty", return_value=False), self.assertRaisesRegex(SystemExit, "interactive terminal"):
            self.project.deploy(None)
        self.assertFalse(any(call.args[:3] == ("sql", "instances", "create") for call in self.project.cli.call_args_list))

    def test_sql_password_stays_out_of_argv_and_temporary_files_are_removed(self):
        self.project.names = Mock(return_value=set())
        paths = []
        def gcloud(*args):
            if args[:3] == ("sql", "instances", "describe"):
                return json.dumps({"databaseVersion": "POSTGRES_16", "region": "europe-west1", "connectionName": "p:r:i"})
            if args[:3] == ("secrets", "versions", "access"):
                path = Path(args[-1].split("=", 1)[1])
                path.write_text("fixture-only")
                paths.append(path)
            if args[:3] == ("sql", "users", "create"):
                path = Path(args[-1].split("=", 1)[1])
                self.assertEqual(json.loads(path.read_text()), {"--password": "fixture-only"})
                paths.append(path)
            self.assertNotIn("fixture-only", args)
            return ""
        self.project.cli.side_effect = gcloud
        self.assertEqual(self.project.database("pixelforge-db", set()), "p:r:i")
        self.assertEqual(len(paths), 2)
        self.assertFalse(any(p.exists() for p in paths))
