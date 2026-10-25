Runwire 3.0 generated source API
================================

The source-scanning generator runs on every Sphinx and Read the Docs build.
It does not require installed PHP extensions, an external site, or access
to any repository other than the checked-out Runwire source.

The generated public-declaration inventory includes typed signatures,
constructor-promoted fields, constants and enum cases. The exception catalog
links explicit ``throw new`` declarations to the PHP source and declaring
type; it intentionally does not claim exhaustive runtime, native extension,
or callback-propagated errors.

.. include:: _generated/public-api.inc
