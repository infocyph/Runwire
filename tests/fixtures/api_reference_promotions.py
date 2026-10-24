#!/usr/bin/env python3
"""Independent, deterministic public property promotion scanning regression."""
import importlib.util
from pathlib import Path

source_file = Path(__file__).resolve().parents[2] / "docs" / "build_api_reference.py"
spec = importlib.util.spec_from_file_location("runwire_api_reference", source_file)
if spec is None or spec.loader is None:
    raise RuntimeError("Unable to load public API reference generator")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

samples = (
    ("public function __construct(public string $inline, public readonly ?\\Foo $qualified) {}",
     ["inline", "qualified"]),
    ("public function __construct(\n    public ?int $id,\n    protected string $hidden,\n    public readonly array $options = [],\n) {}",
     ["id", "options"]),
    ("public function __construct(private string $secret) {}", []),
)
for code, expected in samples:
    signature = module.method_signature(code, code.index("public function"))
    actual = [entry.group(1) for entry in module.PROMOTED.finditer(signature)]
    if actual != expected:
        raise AssertionError(f"Public PHP promotion inventory mismatch: {actual!r} != {expected!r}")
