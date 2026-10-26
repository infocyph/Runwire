#!/usr/bin/env python3
"""Count verified socket-FD read/write syscall families from traced servers.

Development-only perturbing instrumentation: never compare its throughput
against untraced 180-second baseline results.
"""
import json
import re
import sys
from pathlib import Path

SYSCALL = re.compile(
    r"^(?P<operation>read|readv|recvfrom|recvmsg|recvmmsg|write|writev|sendto|sendmsg|sendmmsg)"
    r"\\((?P<fd>[0-9]+)<(?P<kind>TCP|UDP|socket):"
)
READ_FAMILIES = {"read", "readv", "recvfrom", "recvmsg", "recvmmsg"}
WRITE_FAMILIES = {"write", "writev", "sendto", "sendmsg", "sendmmsg"}


def count_socket_calls(lines: list[str]) -> dict:
    reads = 0
    writes = 0
    failures = 0
    by_family: dict[str, int] = {}
    for line in lines:
        match = SYSCALL.match(line)
        if match is None or "<unfinished ...>" in line or "resumed>" in line:
            continue
        operation = match.group("operation")
        result = line.rsplit("=", 1)[-1].strip().split()
        if not result or not re.fullmatch(r"-?[0-9]+", result[0]):
            continue
        if int(result[0]) < 0:
            failures += 1
            continue
        by_family[operation] = by_family.get(operation, 0) + 1
        if operation in READ_FAMILIES:
            reads += 1
        elif operation in WRITE_FAMILIES:
            writes += 1
    return {
        "socket_fd_read_family_successful_syscalls": reads,
        "socket_fd_write_family_successful_syscalls": writes,
        "socket_fd_failed_syscall_attempts": failures,
        "observed_families": by_family,
    }


def main() -> int:
    if len(sys.argv) != 3:
        raise ValueError("Usage: parse.py /path/to/trace-prefix output.json")
    prefix = Path(sys.argv[1])
    sources = sorted(prefix.parent.glob(prefix.name + ".*"))
    if not sources:
        raise ValueError("No strace per-process logs were produced")
    lines = [
        line
        for source in sources
        for line in source.read_text(encoding="utf-8", errors="replace").splitlines()
    ]
    result = count_socket_calls(lines)
    if (result["socket_fd_read_family_successful_syscalls"] < 1
        or result["socket_fd_write_family_successful_syscalls"] < 1):
        raise ValueError("No verified successful socket-FD calls in both directions")
    result.update({
        "schema_version": 1,
        "scope": "Linux-strace-yy-observed-server-socket-fds",
        "trace_process_files": len(sources),
        "trace_performance_overhead": True,
        "untraced_benchmark_equivalence_claimed": False,
        "includes_client_process_calls": False,
    })
    Path(sys.argv[2]).write_text(json.dumps(result, indent=2) + "\\n", encoding="utf-8")
    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError) as error:
        print(f"Socket syscall attribution rejected: {error}", file=sys.stderr)
        sys.exit(1)
