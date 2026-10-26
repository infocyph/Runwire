"""Exercise socket-attribution parser without requiring strace at test import."""
import importlib.util
import unittest
from pathlib import Path

SPEC = importlib.util.spec_from_file_location(
    "socket_attribution",
    Path(__file__).resolve().parents[1] / "benchmarks" / "u08_socket_syscall_parse.py",
)
assert SPEC is not None and SPEC.loader is not None
parser = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(parser)


class NetworkSyscallParserTest(unittest.TestCase):
    def test_counts_real_socket_descriptors_and_ignores_disk_io(self):
        example = [
            'read(4</tmp/file>, "abc", 3) = 3',
            'recvfrom(8<TCP:[127.0.0.1:9000->127.0.0.1:45000]>, "hi", 32, 0, NULL, NULL) = 2',
            'write(9<TCP:[127.0.0.1:9000->127.0.0.1:45000]>, "ok", 2) = 2',
            'sendto(9<socket:[2323]>, "ok", 2, 0, NULL, 0) = 2',
            'read(8<TCP:[... ]>, "", 100) = -1 EAGAIN (Resource temporarily unavailable)',
            'recvfrom(8<TCP:[... ]>,  <unfinished ...>',
        ]
        result = parser.count_socket_calls(example)
        self.assertEqual(result["socket_fd_read_family_successful_syscalls"], 1)
        self.assertEqual(result["socket_fd_write_family_successful_syscalls"], 2)
        self.assertEqual(result["socket_fd_failed_syscall_attempts"], 1)
        self.assertNotIn("read", result["observed_families"])

    def test_rejects_non_socket_file_writes(self):
        result = parser.count_socket_calls(['write(1</tmp/log>, "ok", 2) = 2'])
        self.assertEqual(result["socket_fd_write_family_successful_syscalls"], 0)


if __name__ == "__main__":
    unittest.main()
