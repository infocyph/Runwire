"""Runwire RST-only Sphinx site; internal Markdown plans are excluded."""
import os
import re
import subprocess
import sys
from pathlib import Path
from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers

project = "Runwire"
author = "Infocyph"
version = os.getenv("READTHEDOCS_VERSION", "3.0")
release = version
language = "en"
root_doc = "index"
source_suffix = {".rst": "restructuredtext"}
exclude_patterns = ["plans", "plans/**", "_build"]
extensions = ["sphinx.ext.autosectionlabel"]
autosectionlabel_prefix_document = True
highlight_language = "php"
lexers["php"] = PhpLexer(startinline=True)
html_theme = "sphinx_book_theme"
html_theme_options = {
    "repository_url": "https://github.com/infocyph/Runwire",
    "repository_branch": os.getenv("READTHEDOCS_GIT_IDENTIFIER", os.getenv("GITHUB_HEAD_REF", "main")),
    "path_to_docs": "docs",
    "use_repository_button": True,
    "use_issues_button": True,
}
html_title = "Runwire Manual (" + version + ")"

# Generate source-linked inventory from the checked-out branch, including RTD builds.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_api_reference import generate as generate_api_reference
generate_api_reference()

# The hosted build must display its actual checkout revision, not a claimed tag.
try:
    _revision = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=Path(__file__).resolve().parent.parent,
        text=True, stderr=subprocess.DEVNULL,
    ).strip()
except (OSError, subprocess.CalledProcessError):
    _revision = ""
if not re.fullmatch(r"[0-9a-f]{40}", _revision):
    _revision = "unavailable"
rst_prolog = f".. |runwire_source_revision| replace:: ``{_revision}``"
