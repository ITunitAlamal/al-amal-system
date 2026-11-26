import contextlib
import importlib.util
import io
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "attendance_bridge" / "zk_readonly.py"
SPEC = importlib.util.spec_from_file_location("zk_readonly", SCRIPT)
zk_readonly = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(zk_readonly)


class ProbeOnlyTests(unittest.TestCase):
    def test_probe_only_connects_and_does_not_read_attendance(self):
        class FakeConnection:
            def get_attendance(self):
                raise AssertionError("Health probe must not read attendance records.")

            def disconnect(self):
                pass

        class FakeZK:
            def __init__(self, host, port, timeout, force_udp, ommit_ping):
                self.connection = FakeConnection()

            def connect(self):
                return self.connection

        stdout = io.StringIO()
        module = types.ModuleType("zk")
        module.ZK = FakeZK
        with patch.dict(sys.modules, {"zk": module}), patch.object(
            sys, "argv", ["zk_readonly.py", "--host", "192.0.2.10", "--port", "4370", "--protocol", "tcp", "--probe-only"]
        ), contextlib.redirect_stdout(stdout):
            result = zk_readonly.main()

        self.assertEqual(result, 0)
        self.assertEqual(json.loads(stdout.getvalue())["connected"], True)

    def test_probe_failure_does_not_print_exception_details(self):
        class FakeZK:
            def __init__(self, host, port, timeout, force_udp, ommit_ping):
                pass

            def connect(self):
                raise TimeoutError("private device details")

        stdout = io.StringIO()
        stderr = io.StringIO()
        module = types.ModuleType("zk")
        module.ZK = FakeZK
        with patch.dict(sys.modules, {"zk": module}), patch.object(
            sys, "argv", ["zk_readonly.py", "--host", "192.0.2.10", "--port", "4370", "--protocol", "tcp", "--probe-only"]
        ), contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            result = zk_readonly.main()

        self.assertEqual(result, 2)
        self.assertEqual(stdout.getvalue(), "")
        self.assertNotIn("private device details", stderr.getvalue())


class ExtractionTests(unittest.TestCase):
    def test_raw_codes_and_leading_zeros_are_preserved_without_device_mutation(self):
        from datetime import datetime
        calls = []

        class Connection:
            def get_firmware_version(self):
                calls.append('firmware')
                return 'fixture'

            def get_platform(self):
                return 'fixture'

            def get_time(self):
                return datetime(2026, 9, 1, 8, 0)

            def get_attendance(self):
                calls.append('attendance')
                return [types.SimpleNamespace(user_id='000123', uid=8, timestamp=datetime(2026, 9, 1, 8, 0), status=1, punch=i) for i in range(6)]

            def disconnect(self):
                calls.append('disconnect')

            def __getattr__(self, name):
                raise AssertionError('Unexpected device operation: '+name)

        class FakeZK:
            def __init__(self, host, **options):
                self.options = options
                assert options['password'] == 987654

            def connect(self):
                return Connection()

        module = types.ModuleType('zk')
        module.ZK = FakeZK
        stdout = io.StringIO()
        stderr = io.StringIO()
        with patch.dict(sys.modules, {'zk': module}), patch.object(sys, 'stdin', io.StringIO('{"password":"987654"}')), patch.object(sys, 'argv', ['zk_readonly.py', '--host', '192.0.2.10', '--port', '4370', '--protocol', 'tcp', '--credentials-stdin']), contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
            result = zk_readonly.main()
        self.assertEqual(result, 0)
        payload = json.loads(stdout.getvalue())
        self.assertEqual([e['punch'] for e in payload['events']], list(range(6)))
        self.assertTrue(all(e['uid'] == '000123' and e['status'] == 1 and e['type'] is None for e in payload['events']))
        self.assertEqual(calls, ['firmware', 'attendance', 'disconnect'])
        self.assertNotIn('987654', stdout.getvalue()+stderr.getvalue())


if __name__ == "__main__":
    unittest.main()
