#!/usr/bin/env python3
"""Validate runnable PHP examples and repository pointers in the Sphinx job."""
from pathlib import Path
import re
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parent.parent
docs = root / "docs"
index = (docs / "index.rst").read_text()
readme = (root / "README.md").read_text()
settings = (docs / "conf.py").read_text()
rtd = (root / ".readthedocs.yaml").read_text()

def require(ok: bool, message: str) -> None:
    if not ok:
        raise RuntimeError(message)

require("https://docs.infocyph.com/projects/Runwire" in readme, "Official documentation link absent from README")
require('html_title = "Runwire Manual"' in settings, "Unexpected site title")
require('source_suffix = {".rst": "restructuredtext"}' in settings, "Public docs must use reStructuredText")
require("configuration: docs/conf.py" in rtd, "Read the Docs config missing")
require("Runwire developer handbook" in index, "Handbook title missing")
require(not (docs / "api-reference-3.0.rst").exists(), "Removed API reference page reintroduced")
require(not (docs / "build_api_reference.py").exists(), "Removed API reference generator reintroduced")

pages = sorted(docs.glob("*.rst"))
require(len(pages) >= 25, "Unexpectedly few documentation pages")
index_pages = re.findall(r"^   ([a-z0-9][a-z0-9._-]*)$", index, re.M)
expected = {p.stem for p in pages if p.stem != "index"}
require(set(index_pages) == expected and len(index_pages) == len(expected),
        "Toctree must list every page exactly once")

capabilities = (docs / "features.rst").read_text()
required_capabilities = ("TCP and Unix server", "HTTP/1.1 server", "HTTP/2 server",
                         "HTTP/3 server", "WebSocket server", "Fiber tasks and cancellation",
                         "Async disk jobs", "Native process supervision",
                         "Automatic autoscaling", "Swoole/OpenSwoole")
for capability in required_capabilities:
    require(capability in capabilities, f"Missing feature documentation: {capability}")

example_count = 0
reference_count = 0
with tempfile.TemporaryDirectory(prefix="runwire-sphinx-examples-") as tmp:
    snippet = Path(tmp) / "example.php"
    for page in pages:
        text = page.read_text()
        require(".ci/c3_" not in text, f"Obsolete smoke path in {page}")
        require("api-reference-3.0" not in text, f"Removed API reference link in {page}")
        require("runwire_source_revision" not in text, f"Build fingerprint in {page}")
        for relative in set(re.findall(
            r"\b(?:tests|tools|benchmarks)/[A-Za-z0-9_./-]+\.(?:php|py|sh)\b", text
        )):
            require((root / relative).is_file(),
                    f"Broken fixture or benchmark reference in {page}: {relative}")
            reference_count += 1
        for relative in set(re.findall(r"\btests/smoke/native_[a-z_]+\.php\b", text)):
            require((root / relative).is_file(), f"Missing smoke fixture in {page}: {relative}")
        if "migration" not in page.name and "compatibility" not in page.name:
            lines = text.splitlines()
            header = next((v.strip() for v in lines if v.strip() and not set(v.strip()) <= {"=", "-", "~"}), "")
            require("3.0" not in header, f"Redundant version in chapter title: {page}")
        lines = text.splitlines()
        n = 0
        while n < len(lines):
            if lines[n].strip() != ".. code-block:: php":
                n += 1
                continue
            start = n + 1
            cursor = start
            body = []
            while cursor < len(lines):
                line = lines[cursor]
                if not line:
                    body.append("")
                elif line.startswith("   "):
                    body.append(line[3:])
                else:
                    break
                cursor += 1
            source = "\n".join(body).strip()
            require(bool(source), f"Empty PHP example in {page}:{n + 1}")
            if not source.startswith("<?php"):
                source = "<?php\n" + source
            snippet.write_text(source + "\n")
            result = subprocess.run(["php", "-l", str(snippet)], capture_output=True, text=True)
            require(result.returncode == 0,
                    f"Invalid PHP example in {page}:{n + 1}: {result.stdout} {result.stderr}")
            example_count += 1
            n = max(n + 1, cursor)
require(example_count >= 6, f"Only {example_count} PHP examples validated")
require(reference_count >= 20, f"Only {reference_count} runnable references validated")
print(f"Verified {len(pages)} pages, {example_count} PHP snippets, "
      f"{reference_count} source references and official docs URL.")
