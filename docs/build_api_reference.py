"""Build a complete source-linked PHP API inventory with no non-stdlib dependencies."""
import os
import re
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "src"
TARGET = ROOT / "docs" / "_generated" / "public-api.inc"
REF = os.getenv("GITHUB_SHA") or os.getenv("READTHEDOCS_GIT_COMMIT_HASH") or ""
if not re.fullmatch(r"[0-9a-f]{40}", REF):
    REF = "plan/runwire-independent-ecosystem-roadmaps"
BASE = "https://github.com/infocyph/Runwire/blob/" + REF + "/src/"
BT = chr(96)

TYPE = re.compile(r"(?m)^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z_]\w*)\b")
NAMESPACE = re.compile(r"(?m)^namespace\s+([A-Za-z_\\][\w\\]*)\s*;")
METHOD = re.compile(r"(?m)^\s*(?:(?:final|abstract)\s+)?public\s+(?:static\s+)?function\s+&?\s*(\w+)\s*\(")
PROPERTY = re.compile(r"(?m)^\s*public\s+(?:(?:readonly|static)\s+)*(?:[?\w\\|&]+\s+)?\$(\w+)\b")
CONSTANT = re.compile(r"(?m)^\s*public\s+const\s+(?:\w+\s+)?([A-Za-z_]\w*)\s*=")
CASE = re.compile(r"(?m)^\s*case\s+([A-Za-z_]\w*)\s*(?:[;=])")
THROWN = re.compile(r"\bthrow\s+new\s+(\\?[A-Za-z_][\w\\]*)\s*\(")


def line(source, offset):
    return source.count("\n", 0, offset) + 1


def literal(value):
    return BT + BT + value.replace(BT, "'") + BT + BT


def link(file, number):
    return BT + "source <" + BASE + file.relative_to(SOURCE).as_posix() + "#L" + str(number) + ">" + BT + "__"


def build():
    groups = defaultdict(list)
    exceptions = defaultdict(set)
    files = sorted(SOURCE.rglob("*.php"))
    public_methods = 0
    public_properties = 0
    for file in files:
        source = file.read_text(encoding="utf-8")
        namespace = NAMESPACE.search(source)
        types = list(TYPE.finditer(source))
        if namespace is None or len(types) != 1:
            raise ValueError("Unexpected PHP declaration: " + str(file))
        decl = types[0]
        fqcn = namespace.group(1) + "\\" + decl.group(2)
        pieces = fqcn.split("\\")
        category = pieces[2] if len(pieces) > 2 else "Core"
        entries = ["* " + literal(fqcn) + " (" + decl.group(1) + ") — " + link(file, line(source, decl.start()))]
        methods = sorted([(m.group(1), line(source, m.start())) for m in METHOD.finditer(source)], key=lambda v: v[0].lower())
        public_methods += len(methods)
        if methods:
            entries.append("  - Public methods: " + ", ".join(literal(m) + " (" + link(file, n) + ")" for m, n in methods))
        properties = sorted([(m.group(1), line(source, m.start())) for m in PROPERTY.finditer(source)])
        public_properties += len(properties)
        if properties:
            entries.append("  - Public properties: " + ", ".join(literal(m) + " (" + link(file, n) + ")" for m, n in properties))
        constants = [m.group(1) for m in CONSTANT.finditer(source)]
        if constants:
            entries.append("  - Public constants: " + ", ".join(map(literal, constants)))
        if decl.group(1) == "enum":
            cases = [m.group(1) for m in CASE.finditer(source)]
            if cases:
                entries.append("  - Enum cases: " + ", ".join(map(literal, cases)))
        groups[category].extend(entries)
        for match in THROWN.finditer(source):
            exceptions[match.group(1).lstrip("\\").split("\\")[-1]].add(fqcn)

    heading = "Source-linked PHP API and error reference"
    out = [
        heading, "=" * len(heading), "",
        ".. note::", "",
        "   This document is rebuilt from every PSR-4 PHP source file when Sphinx",
        "   starts. All development symbols, including internal implementation",
        "   types, are enumerated; public methods on internals are not stability",
        "   guarantees. Published stable is Runwire 2.1.2.", "",
        "**" + str(len(files)) + " PHP types** and **" + str(public_methods) + " public methods**",
        "and **" + str(public_properties) + " public properties** are cataloged",
        "directly from this checkout's declarations.", "",
        "PHP types, methods, constants and enum cases",
        "------------------------------------------------", "",
    ]
    for category, entries in sorted(groups.items(), key=lambda item: item[0].lower()):
        out.extend(["**" + category + "**", "", *entries, ""])
    out.extend([
        "Exception catalog", "-----------------", "",
        "The list below scans explicit throw-new statements. Dynamic failures,",
        "host operating-system faults and errors propagated by callbacks are not",
        "fully captured by syntactic scanning.", "",
    ])
    for name, owners in sorted(exceptions.items(), key=lambda item: item[0].lower()):
        out.append("* " + literal(name) + " — " + str(len(owners)) + " declaring PHP type(s)")
    out.extend(["", "See :doc:" + BT + "connection-policies-3.0" + BT + " for trust boundaries and",
                ":doc:" + BT + "migration-3.0" + BT + " for migration guidance.", ""])
    return "\n".join(out)


def generate():
    content = build()
    TARGET.parent.mkdir(parents=True, exist_ok=True)
    if not TARGET.exists() or TARGET.read_text(encoding="utf-8") != content:
        TARGET.write_text(content, encoding="utf-8")


if __name__ == "__main__":
    generate()
