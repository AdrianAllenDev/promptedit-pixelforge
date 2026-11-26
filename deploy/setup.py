#!/usr/bin/env python3
"""Prepare a dedicated Google Cloud project and deploy the current checkout."""
import argparse
import base64
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
APIS = ("run", "cloudbuild", "artifactregistry", "sqladmin", "secretmanager",
        "cloudscheduler", "iam", "cloudresourcemanager")


def prompt_secret(label):
    if not sys.stdin.isatty():
        raise SystemExit(f"Use an interactive terminal to enter {label}.")
    value = getpass.getpass(f"{label} (input hidden): ").strip()
    if not value:
        raise SystemExit(f"Missing {label}.")
    return value


class Project:
    def __init__(self, project_id, region):
        self.id = project_id
        self.region = region

    def cli(self, *args, data=None):
        result = subprocess.run(
            ["gcloud", *args, "--project", self.id, "--quiet"], check=True,
            input=data, stdout=subprocess.PIPE, text=True,
        )
        return result.stdout.strip()

    def names(self, *args, field="name"):
        return {row.rsplit("/", 1)[-1] for row in
                self.cli(*args, f"--format=value({field})").splitlines() if row}

    def ensure_secret(self, name, supply, existing):
        if name in existing:
            versions = self.cli("secrets", "versions", "list", name, "--sort-by=~createTime",
                                "--limit=1", "--format=value(state)")
            if versions == "ENABLED":
                return
            if versions:
                raise SystemExit(f"Latest version of {name} is {versions}. Resolve it before deploying.")
            self.cli("secrets", "versions", "add", name, "--data-file=-", data=supply())
        else:
            self.cli("secrets", "create", name, "--replication-policy=automatic", "--data-file=-", data=supply())

    def account(self, name, existing):
        email = f"{name}@{self.id}.iam.gserviceaccount.com"
        if email not in existing:
            self.cli("iam", "service-accounts", "create", name, f"--display-name={name}")
        return email

    def grant(self, resource, member, role):
        self.cli(*resource, f"--member=serviceAccount:{member}", f"--role=roles/{role}")

    def database(self, instance, instances):
        if instance not in instances:
            print(f"Creating a billable zonal PostgreSQL 16 db-f1-micro instance in {self.region}.", flush=True)
            self.cli("sql", "instances", "create", instance, "--database-version=POSTGRES_16",
                     "--edition=ENTERPRISE", "--tier=db-f1-micro", "--availability-type=ZONAL",
                     "--storage-size=10GB", "--storage-type=SSD", f"--region={self.region}")
        details = json.loads(self.cli("sql", "instances", "describe", instance, "--format=json"))
        if not details["databaseVersion"].startswith("POSTGRES") or details["region"] != self.region:
            raise SystemExit(f"{instance} must be PostgreSQL in {self.region}.")
        instance_arg = f"--instance={instance}"
        if "pixelforge" not in self.names("sql", "databases", "list", instance_arg):
            self.cli("sql", "databases", "create", "pixelforge", instance_arg)
        if "pixelforge" not in self.names("sql", "users", "list", instance_arg):
            # The CLI reads its password flag from a private file, keeping it out of argv and logs.
            with tempfile.TemporaryDirectory(prefix="pixelforge-db-") as directory:
                password_file = Path(directory, "password")
                self.cli("secrets", "versions", "access", "latest", "--secret=pixelforge-db-password",
                         f"--out-file={password_file}")
                flags = Path(directory, "flags.json")
                flags.write_text(json.dumps({"--password": password_file.read_text()}))
                self.cli("sql", "users", "create", "pixelforge", instance_arg, f"--flags-file={flags}")
        return details["connectionName"]

    def deploy(self, chosen_instance):
        self.cli("services", "enable", *(api + ".googleapis.com" for api in APIS))
        number = self.cli("projects", "describe", self.id, "--format=value(projectNumber)")
        instances = self.names("sql", "instances", "list", "--filter=databaseVersion:POSTGRES")
        if not chosen_instance and instances and "pixelforge-db" not in instances:
            raise SystemExit("Existing PostgreSQL instances: " + ", ".join(sorted(instances))
                             + ". Select an authorized instance with --instance and its --region.")
        instance = chosen_instance or "pixelforge-db"
        if chosen_instance and chosen_instance not in instances:
            raise SystemExit(f"Requested existing instance {chosen_instance} was not found.")
        if chosen_instance and chosen_instance != "pixelforge-db":
            target = f"--instance={chosen_instance}"
            if ("pixelforge" not in self.names("sql", "users", "list", target)
                    or "pixelforge" not in self.names("sql", "databases", "list", target)):
                raise SystemExit("For a shared instance, prepare the dedicated pixelforge database and role first.")
        existing = self.names("secrets", "list")
        if instance in instances and "pixelforge-db-password" not in existing:
            if "pixelforge" in self.names("sql", "users", "list", f"--instance={instance}"):
                raise SystemExit("Store the existing pixelforge database password in pixelforge-db-password first.")
        values = {
            "pixelforge-api-key": lambda: prompt_secret("Deployed Pixelforge API key"),
            "pixelforge-webhook-secret": lambda: prompt_secret("Matching Pixelforge webhook signing secret"),
            "pixelforge-app-key": lambda: "base64:" + base64.b64encode(secrets.token_bytes(32)).decode(),
            "pixelforge-db-password": lambda: secrets.token_urlsafe(32),
        }
        for name, supply in values.items():
            self.ensure_secret(name, supply, existing)

        accounts = self.names("iam", "service-accounts", "list", field="email")
        runtime = self.account("pixelforge-runtime", accounts)
        scheduler = self.account("pixelforge-scheduler", accounts)
        builder = self.account("pixelforge-build", accounts)
        for name in values:
            self.grant(["secrets", "add-iam-policy-binding", name], runtime, "secretmanager.secretAccessor")
        self.grant(["projects", "add-iam-policy-binding", self.id], runtime, "cloudsql.client")
        self.grant(["projects", "add-iam-policy-binding", self.id], builder, "logging.logWriter")

        location = f"--location={self.region}"
        if "pixelforge" not in self.names("artifacts", "repositories", "list", location):
            self.cli("artifacts", "repositories", "create", "pixelforge", "--repository-format=docker", location)
        self.grant(["artifacts", "repositories", "add-iam-policy-binding", "pixelforge", location],
                   builder, "artifactregistry.writer")
        bucket = f"pixelforge-source-{number}-{self.region}"
        if bucket not in self.names("storage", "buckets", "list"):
            self.cli("storage", "buckets", "create", f"gs://{bucket}", location,
                     "--uniform-bucket-level-access", "--public-access-prevention")
        self.grant(["storage", "buckets", "add-iam-policy-binding", f"gs://{bucket}"], builder, "storage.objectViewer")
        connection = self.database(instance, instances)
        subprocess.run([str(ROOT / "deploy/cloud-run.sh")], cwd=ROOT, check=True, env={
            **os.environ, "PROJECT_ID": self.id, "REGION": self.region, "INSTANCE_CONNECTION_NAME": connection,
            "RUNTIME_ACCOUNT": runtime, "SCHEDULER_ACCOUNT": scheduler, "BUILD_ACCOUNT": builder,
            "BUILD_SOURCE_BUCKET": bucket,
        })


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("project_id", help="Explicit deployment project ID")
    parser.add_argument("--region", default="europe-west1")
    parser.add_argument("--instance", help="Use this existing PostgreSQL instance in the project")
    args = parser.parse_args()
    os.umask(0o077)
    Project(args.project_id, args.region).deploy(args.instance)
