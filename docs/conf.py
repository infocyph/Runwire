"""Runwire documentation Sphinx configuration."""
import os
from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers

project = "Runwire"
author = "Infocyph"
version = os.getenv("READTHEDOCS_VERSION", "3.0")
release = version
language = "en"
root_doc = "index"
source_suffix = {".rst": "restructuredtext"}
exclude_patterns = ["_build"]
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
html_title = "Runwire Manual"

