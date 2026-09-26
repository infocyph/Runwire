#!/usr/bin/env python3

import argparse
import asyncio
import json
import math
import statistics
import time

import websockets


def percentile(values: list[float], fraction: float) -> float:
    if not values:
        return 0.0
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, math.ceil(fraction * len(ordered)) - 1))
    return ordered[index]


def coefficient_of_variation(values: list[float]) -> float:
    if len(values) < 2:
        return 0.0
    mean = statistics.mean(values)
    if mean <= 0:
        return 0.0
    return statistics.stdev(values) / mean * 100.0


async def round_trip_worker(
    uri: str,
    deadline: float,
    worker_id: int,
    counters: dict[str, int],
    latencies_ms: list[float],
) -> None:
    try:
        async with websockets.connect(
            uri,
            open_timeout=2,
            close_timeout=2,
            max_size=1_048_576,
            max_queue=4,
            ping_interval=None,
            compression=None,
        ) as socket:
            sequence = 0
            while time.perf_counter() < deadline:
                binary = sequence % 8 == 7
                payload = (
                    bytes([worker_id & 0xFF]) + sequence.to_bytes(4, "big") + b"x" * 251
                    if binary
                    else f"{worker_id}:{sequence}:" + "x" * 247
                )
                started = time.perf_counter_ns()
                try:
                    await asyncio.wait_for(socket.send(payload), timeout=2.0)
                    reply = await asyncio.wait_for(socket.recv(), timeout=2.0)
                except asyncio.TimeoutError:
                    counters["timeouts"] += 1
                    return
                except Exception:
                    counters["errors"] += 1
                    return

                counters["messages"] += 1
                if reply != payload:
                    counters["validation_failures"] += 1
                    return
                counters["successful_messages"] += 1
                latencies_ms.append((time.perf_counter_ns() - started) / 1_000_000)
                sequence += 1

                if sequence % 64 == 0:
                    try:
                        pong = await asyncio.wait_for(socket.ping(b"rw"), timeout=2.0)
                        await asyncio.wait_for(pong, timeout=2.0)
                        counters["pings"] += 1
                    except asyncio.TimeoutError:
                        counters["timeouts"] += 1
                        return
                    except Exception:
                        counters["errors"] += 1
                        return
    except asyncio.TimeoutError:
        counters["timeouts"] += 1
    except Exception:
        counters["errors"] += 1


async def run_trial(uri: str, concurrency: int, duration: float) -> dict[str, object]:
    counters = {
        "messages": 0,
        "successful_messages": 0,
        "pings": 0,
        "errors": 0,
        "timeouts": 0,
        "validation_failures": 0,
    }
    latencies: list[float] = []
    started = time.perf_counter()
    deadline = started + duration

    await asyncio.gather(*[
        round_trip_worker(uri, deadline, worker_id, counters, latencies)
        for worker_id in range(concurrency)
    ])

    elapsed = time.perf_counter() - started
    failures = counters["errors"] + counters["timeouts"] + counters["validation_failures"]

    return {
        **counters,
        "duration_seconds": round(elapsed, 6),
        "messages_per_second": round(
            counters["successful_messages"] / elapsed if elapsed > 0 else 0.0,
            3,
        ),
        "latency_ms": {
            "p50": round(percentile(latencies, 0.50), 3),
            "p95": round(percentile(latencies, 0.95), 3),
            "p99": round(percentile(latencies, 0.99), 3),
        },
        "correctness_passed": (
            counters["messages"] > 0
            and counters["successful_messages"] == counters["messages"]
            and failures == 0
        ),
    }


async def slow_reader(uri: str) -> bool:
    payloads = [bytes([index & 0xFF]) * 32_768 for index in range(32)]
    try:
        async with websockets.connect(
            uri,
            open_timeout=2,
            close_timeout=2,
            max_size=1_048_576,
            max_queue=1,
            ping_interval=None,
            compression=None,
        ) as socket:
            for payload in payloads:
                await asyncio.wait_for(socket.send(payload), timeout=2.0)

            await asyncio.sleep(0.25)

            for payload in payloads:
                reply = await asyncio.wait_for(socket.recv(), timeout=3.0)
                if reply != payload:
                    return False

            pong = await asyncio.wait_for(socket.ping(b"slow"), timeout=2.0)
            await asyncio.wait_for(pong, timeout=2.0)
            return True
    except Exception:
        return False


async def soak(uri: str, concurrency: int, duration: float) -> dict[str, object]:
    return await run_trial(uri, concurrency, duration)


async def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("port", type=int)
    parser.add_argument("--concurrency", type=int, default=8)
    parser.add_argument("--duration", type=float, default=3.0)
    parser.add_argument("--trials", type=int, default=5)
    parser.add_argument("--soak-seconds", type=float, default=0.0)
    args = parser.parse_args()

    if not 1 <= args.port <= 65_535:
        raise ValueError("port must be between 1 and 65535")
    if not 1 <= args.concurrency <= 256:
        raise ValueError("concurrency must be between 1 and 256")
    if args.duration <= 0 or args.trials < 1:
        raise ValueError("duration and trials must be positive")
    if args.soak_seconds < 0:
        raise ValueError("soak seconds cannot be negative")

    uri = f"ws://127.0.0.1:{args.port}/ws"
    slow_reader_passed = await slow_reader(uri)

    trials = []
    for _ in range(args.trials):
        trial = await run_trial(uri, args.concurrency, args.duration)
        trials.append(trial)

    rates = [float(trial["messages_per_second"]) for trial in trials]
    p95 = [float(trial["latency_ms"]["p95"]) for trial in trials]
    p99 = [float(trial["latency_ms"]["p99"]) for trial in trials]
    errors = sum(int(trial["errors"]) for trial in trials)
    timeouts = sum(int(trial["timeouts"]) for trial in trials)
    validation_failures = sum(int(trial["validation_failures"]) for trial in trials)
    messages = sum(int(trial["messages"]) for trial in trials)
    successful = sum(int(trial["successful_messages"]) for trial in trials)
    pings = sum(int(trial["pings"]) for trial in trials)

    soak_result = None
    if args.soak_seconds > 0:
        soak_result = await soak(uri, args.concurrency, args.soak_seconds)

    passed = (
        slow_reader_passed
        and messages > 0
        and successful == messages
        and errors == 0
        and timeouts == 0
        and validation_failures == 0
        and all(bool(trial["correctness_passed"]) for trial in trials)
        and (
            soak_result is None
            or bool(soak_result["correctness_passed"])
        )
    )

    result = {
        "client": f"python-websockets/{websockets.__version__}",
        "protocol": "rfc6455-http1",
        "compression": "disabled",
        "concurrency": args.concurrency,
        "trials": args.trials,
        "duration_seconds_per_trial": args.duration,
        "messages_total": messages,
        "successful_messages": successful,
        "pings_total": pings,
        "errors_total": errors,
        "timeouts_total": timeouts,
        "validation_failures": validation_failures,
        "median_messages_per_second": round(statistics.median(rates), 3),
        "rate_cv_percent": round(coefficient_of_variation(rates), 3),
        "median_p95_ms": round(statistics.median(p95), 3),
        "median_p99_ms": round(statistics.median(p99), 3),
        "slow_reader_passed": slow_reader_passed,
        "soak_seconds": args.soak_seconds,
        "soak_correctness_passed": None if soak_result is None else bool(soak_result["correctness_passed"]),
        "correctness_passed": passed,
    }
    print(json.dumps(result, indent=2, sort_keys=True))

    if not passed:
        raise RuntimeError("Native WebSocket interoperability/soak evidence failed.")


if __name__ == "__main__":
    asyncio.run(main())
