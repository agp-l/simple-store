"""The scheduled worker must not forward credentials or run after idle/paused."""
import importlib.util
import io
import json
import sys
import urllib.error
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
spec = importlib.util.spec_from_file_location("supplier_worker", Path(__file__).resolve().parents[1] / "scripts/run-supplier-worker.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WorkerTests(unittest.TestCase):
    def test_no_credential_forwarding(self):
        self.assertIsNone(worker.NoRedirect().redirect_request(None, None, 302, "", {}, "https://other.test/"))

    def test_request_and_idle(self):
        calls = []
        class Opener:
            def open(self, request, timeout):
                calls.append(request)
                return io.BytesIO(json.dumps({"state": "idle", "wait": 30}).encode())
        with patch.object(worker, "worker_key", return_value="a" * 64), patch.object(worker.urllib.request, "build_opener", return_value=Opener()), patch.object(worker.time, "sleep") as sleep:
            worker.run()
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0].get_method(), "POST")
        self.assertEqual(calls[0].full_url, worker.ENDPOINT)
        self.assertNotIn("a" * 64, calls[0].full_url)
        self.assertEqual(calls[0].get_header("X-supplier-worker-key"), "a" * 64)
        sleep.assert_not_called()

    def test_future_queue_returns_without_busy_waiting(self):
        class Opener:
            def open(self, request, timeout):
                return io.BytesIO(json.dumps({"state": "waiting", "wait": 60, "next_at": 86400}).encode())
        with patch.object(worker, "worker_key", return_value="a" * 64), patch.object(worker.urllib.request, "build_opener", return_value=Opener()), patch.object(worker.time, "time", return_value=1000), patch.object(worker.time, "sleep") as sleep:
            worker.run()
        sleep.assert_not_called()

    def run_responses(self, responses):
        opener = unittest.mock.Mock()
        opener.open.side_effect = responses
        output = io.StringIO()
        with patch.object(worker, "worker_key", return_value="a" * 64), patch.object(worker.urllib.request, "build_opener", return_value=opener), patch.object(worker.time, "sleep") as sleep, patch("sys.stdout", output):
            worker.run()
        return opener, sleep, output.getvalue()

    def http_error(self, code, headers=None, body=b"private upstream body"):
        return urllib.error.HTTPError(worker.ENDPOINT, code, "private error", headers or {}, io.BytesIO(body))

    def test_transient_failure_recovers_without_logging_body_or_key(self):
        opener, sleep, output = self.run_responses([
            TimeoutError("private exception"), self.http_error(503, {"Retry-After": "45"}),
            io.BytesIO(b'{"state":"idle"}')])
        self.assertEqual(opener.open.call_count, 3)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [15, 45])
        self.assertIn("HTTP 503", output)
        self.assertNotIn("private", output.replace("loading private configuration", ""))
        self.assertNotIn("a" * 64, output)

    def test_authenticated_error_diagnostic_excludes_exception_message(self):
        error = self.http_error(503, body=b'{"phase":"tick","kind":"MeekroDBException","code":"42S02","message":"private SQL"}')
        self.assertEqual(worker.http_diagnostic(error), "HTTP 503; phase=tick; kind=MeekroDBException; code=42S02")

    def test_permanent_errors_and_long_retry_after_are_not_retried(self):
        for error in (self.http_error(403), self.http_error(503, {"Retry-After": "3600"})):
            opener = unittest.mock.Mock()
            opener.open.side_effect = error
            with self.subTest(code=error.code), patch.object(worker, "worker_key", return_value="a" * 64), patch.object(worker.urllib.request, "build_opener", return_value=opener), patch.object(worker.time, "sleep") as sleep:
                with self.assertRaises(urllib.error.HTTPError):
                    worker.run()
                sleep.assert_not_called()
                self.assertEqual(opener.open.call_count, 1)

    def test_retries_are_bounded(self):
        opener = unittest.mock.Mock()
        opener.open.side_effect = TimeoutError("private exception")
        with patch.object(worker, "worker_key", return_value="a" * 64), patch.object(worker.urllib.request, "build_opener", return_value=opener), patch.object(worker.time, "sleep") as sleep:
            with self.assertRaises(TimeoutError):
                worker.run()
        self.assertEqual(opener.open.call_count, 3)
        self.assertEqual(sleep.call_count, 2)


unittest.main()
