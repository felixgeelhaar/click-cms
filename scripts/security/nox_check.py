#!/usr/bin/env python3
"""Run Nox and reject incomplete reports as well as policy failures."""
import argparse
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile


def validate_report(report):
    if not isinstance(report, dict) or not isinstance(report.get("findings"), list):
        raise ValueError("Nox did not produce a findings report")
    meta = report.get("meta")
    if not isinstance(meta, dict) or meta.get("tool_name") != "nox":
        raise ValueError("Missing Nox report metadata")
    for finding in report["findings"]:
        if (not isinstance(finding, dict)
                or finding.get("Severity") not in ("critical", "high", "medium", "low", "info")
                or not isinstance(finding.get("RuleID"), str)
                or not finding["RuleID"]):
            raise ValueError("Malformed Nox finding")
        # Enforce the repository's fail-on-high policy independently of the
        # scanner process/action exit status, including --report in CI.
        if finding["Severity"] in ("high", "critical") and finding.get("Status") != "suppressed":
            raise ValueError("Blocking Nox finding: " + finding["RuleID"])
    degradations = meta.get("degradations", [])
    if not isinstance(degradations, list) or any(not isinstance(d, dict) for d in degradations):
        raise ValueError("Malformed Nox degradation metadata")
    # Unselected plugins installed globally are advisory, not failed checks.
    # They still remain visible in Nox's output and the retained report.
    failed = [d for d in degradations if d.get("advisory") is not True]
    if failed:
        raise ValueError("Incomplete Nox scan: " + "; ".join(str(d.get("detail", d)) for d in failed))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--offline", action="store_true", help="Fast local secrets/code/IaC checks; no dependency advisory lookup")
    parser.add_argument("--report", type=Path, help="Validate an existing CI report and enforce the high/critical findings policy")
    args = parser.parse_args()
    if args.report:
        validate_report(json.loads(args.report.read_text()))
        return 0
    root = Path(__file__).resolve().parents[2]
    destination = root / ".nox/reports" / ("offline" if args.offline else "full")
    destination.mkdir(parents=True, exist_ok=True)
    # Never accept an artifact left over from an earlier scan.
    for name in ("findings.json", "results.sarif"):
        (destination / name).unlink(missing_ok=True)
    with tempfile.TemporaryDirectory(prefix="click-nox-") as directory:
        command = ["nox", "scan", ".", "--no-auto-install", "--format", "json,sarif", "--output", directory]
        if args.offline:
            command.extend(["--offline", "--only", "secrets,code,iac"])
        result = subprocess.run(command, cwd=root, check=False)
        for source in Path(directory).iterdir():
            if source.is_file():
                shutil.copyfile(source, destination / source.name)
        report = json.loads((destination / "findings.json").read_text())
        validate_report(report)
        return result.returncode


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError) as error:
        print(f"Security check failed: {error}", file=sys.stderr)
        sys.exit(2)
