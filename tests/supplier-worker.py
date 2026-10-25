"""The scheduled worker must not forward credentials or run after idle/paused."""
import importlib.util
import io
import json
import sys
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


unittest.main()
