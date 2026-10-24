"""Runwire RST-only Sphinx site; internal Markdown plans are excluded."""
import os
import sys
from pathlib import Path
from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers

project = "Runwire"
author = "Infocyph"
version = os.getenv("READTHEDOCS_VERSION", "3.0-development")
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
    "repository_branch": "main",
    "path_to_docs": "docs",
    "use_repository_button": True,
    "use_issues_button": True,
}
html_title = "Runwire Manual (" + version + ")"

# Generate source-linked inventory from the checked-out branch, including RTD builds.
sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_api_reference import generate as generate_api_reference
generate_api_reference()
