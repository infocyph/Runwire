#!/usr/bin/env python3
"""Run and evaluate the complete J10 matrix; never modify production defaults."""

import argparse
import hashlib
import itertools
import json
import math
import os
from pathlib import Path
import platform
import signal
import socket
import subprocess
import sys
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
MODES = ("fixed", "latency", "throughput", "auto")
PROTOCOLS = ("http1", "h2", "h3")
PROTOCOL_NAMES = {"http1": "http/1.1", "h2": "h2", "h3": "h3"}
PHASES = ("low_before", "medium", "transition_up", "high", "transition_down", "low_after")


def cases(protocol: str) -> list[dict]:
    result = []
    sizes = (2, 1024, 16384, 65536)
    if protocol == "http1":
        for tls, size, reuse in itertools.product((False, True), sizes, (8, 800)):
            result.append(dict(id=f"h1-{'tls' if tls else 'plain'}-{size}-reuse{reuse}",
                               protocol=protocol, tls=tls, payload_bytes=size, keepalive=reuse,
                               scenario="steady", low=1, medium=32, high=256))
    elif protocol == "h2":
        for streams in (1, 8, 32, 100):
            for size in sizes:
                result.append(dict(id=f"h2-{size}-s{streams}", protocol=protocol,
                                   payload_bytes=size, scenario="steady", low=1,
                                   medium=max(1, streams // 2), high=streams))
            for scenario in ("mixed", "flow", "pressure"):
                result.append(dict(id=f"h2-{scenario}-s{streams}", protocol=protocol,
                                   payload_bytes=65536, scenario=scenario, low=1,
                                   medium=max(1, streams // 2), high=streams))
    elif protocol == "h3":
        for streams, size, direction in itertools.product((1, 32, 80), sizes, ("inbound", "outbound", "balanced")):
            upload = max(65536, size * 4) if direction == "inbound" else (size if direction == "balanced" else 0)
            result.append(dict(id=f"h3-{direction}-{size}-s{streams}", protocol=protocol,
                               payload_bytes=size, upload_bytes=upload, scenario=direction,
                               low=1, medium=max(1, streams // 2), high=streams))
        for streams, scenario in itertools.product((1, 32, 80), ("qpack", "churn")):
            result.append(dict(id=f"h3-{scenario}-s{streams}", protocol=protocol,
                               payload_bytes=1024, upload_bytes=0, scenario=scenario,
                               low=1, medium=max(1, streams // 2), high=streams))
    else:
        raise ValueError(f"Unknown protocol: {protocol}")
    return result


def json_file(path: Path):
    return json.loads(path.read_text(), parse_constant=lambda value: (_ for _ in ()).throw(ValueError(value)))


def write_json(path: Path, value) -> None:
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(value, indent=2, sort_keys=True, allow_nan=False) + "\n")
    temporary.replace(path)


def code_identity() -> str:
    digest = hashlib.sha256()
    for directory in ("src", "benchmarks"):
        for path in sorted((ROOT / directory).rglob("*")):
            if path.is_file() and path.suffix in {".php", ".py", ".sh"}:
                digest.update(str(path.relative_to(ROOT)).encode() + b"\0" + path.read_bytes())
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip() + ":" + digest.hexdigest()


def python_for(protocol: str) -> str:
    return {"http1": sys.executable,
            "h2": os.environ.get("RUNWIRE_H2_PYTHON", "/tmp/runwire-h2/bin/python"),
            "h3": os.environ.get("RUNWIRE_AIOQUIC_PYTHON", "/tmp/runwire-aioquic/bin/python")}[protocol]


def environment(protocol: str) -> dict:
    php = subprocess.check_output(["php", "-d", "opcache.enable_cli=1", "-r", '''
    $extensions = [];
    foreach (get_loaded_extensions() as $name) { $extensions[$name] = phpversion($name); }
    echo json_encode(['php' => PHP_VERSION, 'extensions' => $extensions,
        'opcache' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
        'ini' => ini_get_all(null, false)]);
    '''], cwd=ROOT, text=True)
    runtime = json.loads(php)
    required = {"event", "pcntl", "posix", "openssl"} | ({"quic"} if protocol == "h3" else set())
    if not required.issubset(runtime["extensions"]) or not runtime["opcache"] or "xdebug" in runtime["extensions"]:
        raise RuntimeError("Prepared native extensions, enabled CLI OPcache, and no Xdebug are required")
    client = {"python": subprocess.check_output([python_for(protocol), "--version"], text=True).strip()}
    package = {"h2": ("h2", "4.3.0"), "h3": ("aioquic", "1.3.0")}.get(protocol)
    if package:
        version = subprocess.check_output([python_for(protocol), "-c",
            f"import importlib.metadata; print(importlib.metadata.version('{package[0]}'))"], text=True).strip()
        if version != package[1]:
            raise RuntimeError(f"Expected {package[0]}=={package[1]}, found {version}")
        client[package[0]] = version
    return dict(host=platform.node(), system=platform.platform(), cpu=sorted({line for line in Path('/proc/cpuinfo').read_text().splitlines() if line.startswith(('vendor_id', 'model name', 'cpu family', 'cpu cores', 'microcode'))}),
                runtime=runtime, client=client, build=code_identity())


def validate_record(record: dict, case: dict, mode: str, trial: int, identity: dict) -> None:
    expected = dict(case=case, mode=mode, trial=trial, workload=case["id"],
                    protocol=PROTOCOL_NAMES[case["protocol"]], payload_bytes=case["payload_bytes"],
                    runtime_build=identity["build"], environment=identity["environment_id"])
    if any(record.get(key) != value for key, value in expected.items()):
        raise ValueError("Trial identity, workload, mode or environment mismatch")
    if not record.get("run_id") or record.get("correctness_passed") is not True or set(record.get("phases", {})) != set(PHASES):
        raise ValueError("Trial is missing identity, correct responses or mandatory phases")
    for name, phase in record["phases"].items():
        target = case["medium"] if name == "medium" else case["high"] if name in {"high", "transition_up"} else case["low"]
        if phase.get("concurrency") != target or phase.get("correctness_passed") is not True:
            raise ValueError("Phase did not exercise its declared concurrency correctly")
        observed = phase.get("observed", {})
        scenario = case["scenario"]
        required = "delayed_reads" if scenario == "pressure" else "constrained_flow_bytes" if scenario == "flow" else "qpack_delayed_headers" if scenario == "qpack" else None
        if required and observed.get(required, 0) <= 0:
            raise ValueError(f"Case did not exercise {required}")
        if case.get("upload_bytes", 0) and observed.get("upload_bytes") != phase.get("requests", 0) * case["upload_bytes"]:
            raise ValueError("Upload workload was not completed and validated")
        if scenario == "churn" and observed.get("connections", 0) < math.ceil(phase.get("requests", 0) / 8):
            raise ValueError("Churn workload did not rotate connections")
        client_cpu = phase.get("client_cpu_percent")
        if not isinstance(client_cpu, (int, float)) or not math.isfinite(client_cpu) or client_cpu < 0:
            raise ValueError("Missing valid generator CPU measurement")


def evaluate_protocol(case_results: dict, protocol: str) -> dict:
    required = {case["id"] for case in cases(protocol)}
    failures = []
    gains = []
    for name in sorted(required):
        result = case_results.get(name)
        if not result or result.get("valid") is not True:
            failures.append(f"{name}: missing or invalid trial evidence")
            continue
        comparisons = result.get("comparisons", {})
        for baseline in MODES[:-1]:
            comparison = comparisons.get(baseline, {})
            if comparison.get("passed") is not True or comparison.get("measurement_qualified") is not True:
                failures.append(f"{name}: {baseline} regression or measurement gate failed")
        fixed = comparisons.get("fixed", {}).get("phases", {})
        for phase in ("transition_up", "transition_down"):
            delta = fixed.get(phase, {})
            if delta.get("throughput_ratio", 0) < 0.99 or delta.get("p95_absolute_delta_ms", math.inf) > 0.1 or delta.get("p99_absolute_delta_ms", math.inf) > 0.1:
                failures.append(f"{name}/{phase}: transition overhead exceeds 1% throughput or 0.1 ms latency")
        for phase in ("low_before", "medium", "high", "low_after"):
            delta = fixed.get(phase, {})
            noise = delta.get("combined_rps_cv_percent", math.inf) * 2
            if delta.get("throughput_ratio", 0) >= 1.05 and (delta["throughput_ratio"] - 1) * 100 > noise:
                gains.append(f"{name}/{phase}")
    if not gains:
        failures.append("No steady workload demonstrated the preregistered 5% material throughput gain")
    return dict(protocol=protocol, required_cases=len(required), material_gain_cases=gains,
                failures=failures, performance_gate_passed=not failures,
                decision="performance-qualified" if not failures else "not-certified",
                promotion_certified=False)


def evaluate(output: Path, protocol: str, identity: dict) -> dict:
    results = {}
    run_ids = set()
    for case in cases(protocol):
        directory = output / case["id"]
        result = {"valid": False, "comparisons": {}}
        try:
            for mode in MODES:
                paths = [directory / f"{mode}-{trial}.json" for trial in range(1, 6)]
                for trial, path in enumerate(paths, 1):
                    if path.with_name(path.stem + "-failure.json").exists():
                        raise ValueError("Recorded failed trial cannot certify a matrix cell")
                    record = json_file(path)
                    validate_record(record, case, mode, trial, identity)
                    if record["run_id"] in run_ids:
                        raise ValueError("Duplicate trial run ID")
                    run_ids.add(record["run_id"])
                summary = subprocess.check_output(["php", "benchmarks/adaptive_matrix_summary.php", *map(str, paths)], cwd=ROOT, text=True)
                write_json(directory / f"{mode}-summary.json", json.loads(summary))
            for mode in MODES[:-1]:
                comparison = subprocess.run(["php", "benchmarks/adaptive_matrix_compare.php",
                    str(directory / "auto-summary.json"), str(directory / f"{mode}-summary.json"),
                    f"--baseline={mode}"], cwd=ROOT, text=True, capture_output=True)
                value = json.loads(comparison.stdout)
                if comparison.returncode and value.get("passed") is not False:
                    raise ValueError("Comparator failed without a valid failure result")
                write_json(directory / f"auto-vs-{mode}.json", value)
                result["comparisons"][mode] = value
            result["valid"] = True
        except (OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
            result["error"] = str(error)
        results[case["id"]] = result
    report = evaluate_protocol(results, protocol)
    report.update(build=identity["build"], environment=identity["environment_id"], cases=results)
    write_json(output / f"{protocol}-promotion.json", report)
    return report


def stop_server(server: subprocess.Popen) -> None:
    if server.poll() is not None:
        raise RuntimeError(f"Benchmark server exited unexpectedly ({server.returncode})")
    os.killpg(server.pid, signal.SIGTERM)
    try:
        server.wait(timeout=10)
    except subprocess.TimeoutExpired:
        os.killpg(server.pid, signal.SIGKILL)
        server.wait()
        raise RuntimeError("Benchmark server failed graceful shutdown")
    if server.returncode not in (0, -signal.SIGTERM):
        raise RuntimeError(f"Benchmark server failed during shutdown ({server.returncode})")


def run_trial(output: Path, case: dict, mode: str, trial: int, seconds: float, warmup: float,
              identity: dict, temporary: Path) -> None:
    protocol = case["protocol"]
    directory = output / case["id"]
    directory.mkdir(exist_ok=True)
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM if protocol == "h3" else socket.SOCK_STREAM) as probe:
        probe.bind(("127.0.0.1", 0))
        port = str(probe.getsockname()[1])
    ready = temporary / "ready"
    ready.unlink(missing_ok=True)
    cert, key = str(temporary / "cert.pem"), str(temporary / "key.pem")
    command = ["php", "-d", "opcache.enable_cli=1"]
    if protocol == "h3":
        command += ["benchmarks/adaptive_http3_server.php", port, cert, key, str(ready), mode, str(case["payload_bytes"])]
    else:
        command += ["benchmarks/adaptive_http_server.php", protocol, port, mode, str(case["payload_bytes"])]
        if protocol == "h2" or case["tls"]:
            command += [cert, key]
    env = dict(os.environ, RUNWIRE_ADAPTIVE_CASE=json.dumps(case), RUNWIRE_ADAPTIVE_TRIAL=str(trial),
               RUNWIRE_ADAPTIVE_BUILD=identity["build"], RUNWIRE_ADAPTIVE_ENVIRONMENT=identity["environment_id"],
               RUNWIRE_ADAPTIVE_WARMUP_SECONDS=str(warmup))
    with (directory / f"{mode}-{trial}-server.log").open("w") as log:
        server = subprocess.Popen(command, cwd=ROOT, stdout=log, stderr=log, start_new_session=True)
        try:
            deadline = time.monotonic() + 10
            while True:
                if server.poll() is not None or time.monotonic() >= deadline:
                    raise RuntimeError("Benchmark server failed readiness; inspect its retained log")
                if protocol == "h3":
                    if ready.exists():
                        break
                else:
                    try:
                        with socket.create_connection(("127.0.0.1", int(port)), timeout=0.1):
                            break
                    except OSError:
                        pass
                time.sleep(0.05)
            script = {"http1": "http1_adaptive_matrix.py", "h2": "h2_adaptive_matrix.py", "h3": "http3_adaptive_matrix.py"}[protocol]
            client = [python_for(protocol), f"benchmarks/{script}", port, str(server.pid), mode, str(seconds)]
            if protocol != "http1":
                client.append(str(case["payload_bytes"]))
            with (directory / f"{mode}-{trial}-client.log").open("w") as errors:
                raw = subprocess.check_output(client, cwd=ROOT, env=env, text=True, stderr=errors,
                                              timeout=warmup + 4 * seconds + 60)
            record = json.loads(raw)
            record["run_id"] = str(uuid.uuid4())
            validate_record(record, case, mode, trial, identity)
        finally:
            stop_server(server)
    if code_identity() != identity["build"]:
        raise RuntimeError("Source changed during the trial; use one immutable candidate")
    write_json(directory / f"{mode}-{trial}.json", record)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=("list", "run", "evaluate"))
    parser.add_argument("--protocol", choices=PROTOCOLS, required=True)
    parser.add_argument("--output", type=Path, default=Path("benchmark-results/adaptive-promotion"))
    parser.add_argument("--case", action="append", default=[])
    parser.add_argument("--seconds", type=float, default=180)
    parser.add_argument("--warmup", type=float, default=30)
    parser.add_argument("--diagnostic", action="store_true")
    parser.add_argument("--resume", action="store_true")
    args = parser.parse_args()
    selected = [case for case in cases(args.protocol) if not args.case or case["id"] in args.case]
    if args.case and set(args.case) != {case["id"] for case in selected}:
        parser.error("Unknown case ID")
    if args.action == "list":
        print(json.dumps(selected, indent=2))
        return 0
    output = args.output.resolve()
    path = output / f"{args.protocol}-environment.json"
    if args.action == "evaluate":
        report = evaluate(output, args.protocol, json_file(path))
        print(json.dumps({key: value for key, value in report.items() if key != "cases"}, indent=2))
        return 0 if report["performance_gate_passed"] else 1
    if not all(math.isfinite(v) and v >= 1 for v in (args.seconds, args.warmup)):
        parser.error("Durations must be finite and at least one second")
    if not args.diagnostic and (args.seconds < 180 or args.warmup < 30):
        parser.error("Sustained runs require --seconds >= 180 and --warmup >= 30")
    identity = environment(args.protocol)
    identity["environment_id"] = hashlib.sha256(json.dumps(identity, sort_keys=True).encode()).hexdigest()
    output.mkdir(parents=True, exist_ok=True)
    if path.exists():
        if not args.resume or json_file(path) != identity:
            raise RuntimeError("Use a fresh output directory or --resume with exactly matching build/environment")
    else:
        write_json(path, identity)
    with tempfile.TemporaryDirectory(prefix="runwire-j10-") as name:
        temporary = Path(name)
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "7",
                        "-subj", "/CN=localhost", "-keyout", str(temporary / "key.pem"), "-out", str(temporary / "cert.pem")],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for case in selected:
            for trial in range(1, 6):
                # Rotate the four modes so each gets every position, then reverse for trial five.
                offset = (trial - 1) % 4
                order = MODES[offset:] + MODES[:offset] if trial < 5 else tuple(reversed(MODES))
                for mode in order:
                    record_path = output / case["id"] / f"{mode}-{trial}.json"
                    failure_path = output / case["id"] / f"{mode}-{trial}-failure.json"
                    if failure_path.exists():
                        raise RuntimeError("A failed trial cannot be silently retried; retain this evidence and use a fresh output directory")
                    if record_path.exists() and args.resume:
                        validate_record(json_file(record_path), case, mode, trial, identity)
                        continue
                    print(f"{case['id']} {mode} trial {trial}/5", flush=True)
                    try:
                        run_trial(output, case, mode, trial, args.seconds, args.warmup, identity, temporary)
                    except BaseException as error:
                        failure_path.parent.mkdir(exist_ok=True)
                        write_json(failure_path, dict(error=str(error), build=identity["build"], mode=mode, trial=trial))
                        raise
    report = evaluate(output, args.protocol, identity)
    print(f"{args.protocol}: {report['decision']} ({len(report['failures'])} unmet requirements)")
    # Diagnostic runs and partial selections remain non-certifying, including their exit status.
    return 0 if report["performance_gate_passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
