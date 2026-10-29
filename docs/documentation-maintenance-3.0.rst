Documentation maintenance and contribution standards
====================================================

Purpose
-------

The documentation is a public product surface and must track the same
source, QA and security reality as the library. Runwire publishes
**native reStructuredText only** for Sphinx. Historical Markdown
plans and evidence remain internal; they are not the hosted manual.

The current stable package is 2.1.2 and 3.0 is a development
candidate. Distinguish released behavior, verified development
capability, optional extension-dependent behavior and unsupported
features in every document.

Documentation information architecture
--------------------------------------

The root `docs/index.rst` groups entry points by reader and topic.
Top-level pages give concepts and worked usage; deeper references
explain public symbols, protocol state and operational playbooks.

* Source declaration truth: `src/` and generated API.
* Public feature truth: `docs/features.rst`.
* Architecture and implementation: the 3.0 handbook.
* RST source reference: `docs/api-reference-3.0.rst` and generator.
* Compatibility: `docs/migration-3.0.rst` plus migration playbook.
* Immutable release baseline and API-change reviews: `qa/release-baseline/`.
* Historical planning and completed trackers: Git history, not the public manual.
* Runner truth: `tests/`, `.ci/` and `.github/workflows/`.

Writing guidance
----------------

Write explanations in terms of ownership, admission, deadlines,
failure behavior and resource limits. Whenever documenting an
optional backend, specify actual extension/OS capability and what
happens when it is missing. Avoid saying all HTTP versions,
WebSockets or async files have identical semantics.

Keep examples runnable on a stated host and PHP version. For a
source-backed fixture, name its exact repository path and the
command needed to run it. Never use examples that require an
undefined service, certificate or background daemon while calling
them self-contained.

Do not promise kernel filesystem AIO, WebTransport, implicit TLS
downgrade or nested HTTPS-proxy TLS unless source code and independent
QA genuinely provide it.

Sphinx architecture
-------------------

`docs/conf.py` configures RST-only sources, source-linked generated
PHP API references and `sphinx-book-theme`. `docs/requirements.txt`
pins Sphinx dependencies. The repository root `.readthedocs.yaml`
declares the Read the Docs v2 build. The generated API builder scans
the **checked-out source revision**, not a downloaded separate
package; links include source filenames and line numbers.

Local verification:

.. code-block:: console

   python -m pip install -r docs/requirements.txt
   sphinx-build -n -W --keep-going -b html docs build/sphinx-html

For full PHP fixture checks:

.. code-block:: console

   vendor/bin/pest tests/Plan3SphinxRstTest.php
   vendor/bin/pest tests/Plan3DocumentationSnippetsTest.php
   vendor/bin/pest tests/GeneratedApiCoverageTest.php

A local build is necessary but not always sufficient. Read the Docs
hosted preview, search, version selectors, external link handling
and source feature coverage require separate evidence. Do not mark
the hosted preview accepted merely because static HTML built
successfully in GitHub Actions.

Changing the public API
-----------------------

When code adds, removes or changes a public symbol:

1. Update generated-API test fixtures and verify the source scanner.
2. Update the relevant conceptual guide and runnable example.
3. Update `docs/features.rst` for host/extension maturity.
4. Update the migration playbook and released-to-development delta.
5. Add or update a focused functional/negative QA scenario.
6. Execute strict Sphinx, snippet and link checks.
7. Synchronize the canonical development plan and roadmap with
   **actually verified** evidence, not intended completion.

A source-only manifest difference can be a trait move rather than a
removed method. Use reflection and the frozen 2.1.2 manifest to
distinguish such changes.

Review checklist
----------------

* All new public RST pages appear in the root toctree.
* Sphinx completes with warnings treated as errors.
* No broken cross-page references, duplicate labels or orphan pages.
* Source-linked type names and function signatures are verified.
* Every example names its prerequisites, runtime and failure path.
* Security boundaries and unsupported feature statements are explicit.
* Historical guides are visibly versioned and not mistaken for 3.0.
* Quantitative claims include methodology, source SHA and raw CI evidence.
* Public site does not publish internal draft plans or secrets.
* Final release/hosted preview acceptance is tracked independently.

See :doc:`api-reference-3.0`, :doc:`handbook-3.0` and
:doc:`migration-playbook-3.0` for the reader-facing result.
