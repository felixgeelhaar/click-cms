"""The report gate must distinguish an incomplete scan from a passing one."""
import unittest

from nox_check import validate_report


class ReportGateTest(unittest.TestCase):
    def report(self, **meta):
        return {"meta": {"tool_name": "nox", **meta}, "findings": []}

    def test_complete_report_passes(self):
        validate_report(self.report())

    def test_missing_report_shape_fails(self):
        for report in ({}, {"meta": {}, "findings": []}, self.report(degradations="broken")):
            with self.subTest(report=report), self.assertRaises(ValueError):
                validate_report(report)

    def test_malformed_finding_blocks(self):
        for finding in (None, "broken", {}, {"Severity": "unknown"}):
            with self.subTest(finding=finding), self.assertRaises(ValueError):
                validate_report({**self.report(), "findings": [finding]})

    def test_active_high_and_critical_findings_block(self):
        for severity in ("high", "critical"):
            with self.subTest(severity=severity), self.assertRaises(ValueError):
                validate_report({**self.report(), "findings": [{"Severity": severity, "RuleID": "TEST-001"}]})

    def test_suppressed_high_findings_remain_reportable(self):
        validate_report({**self.report(), "findings": [{"Severity": "high", "RuleID": "TEST-001", "Status": "suppressed"}]})

    def test_medium_findings_remain_nonblocking(self):
        validate_report({**self.report(), "findings": [{"Severity": "medium", "RuleID": "TEST-001"}]})

    def test_failed_analyzer_blocks(self):
        with self.assertRaises(ValueError):
            validate_report(self.report(degradations=[{"kind": "osv", "detail": "lookup failed"}]))

    def test_unselected_global_plugins_are_advisory(self):
        validate_report(self.report(degradations=[{"kind": "plugin", "advisory": True, "detail": "not selected"}]))

    def test_malformed_degradation_blocks(self):
        with self.assertRaises(ValueError):
            validate_report(self.report(degradations=["broken"]))


if __name__ == "__main__":
    unittest.main()
