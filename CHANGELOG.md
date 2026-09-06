# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial Symfony 8.1 microservice implementation for the OAI-PMH 2.0 component with PHP 8.4 and FrankenPHP.
- Support for all six OAI-PMH verbs (`Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`, `ListRecords`, `GetRecord`).
- Solr-based metadata serving for Dublin Core (`oai_dc`), TEI (`tei`), OpenAIRE (`oai_openaire`), and Crossref (`crossref`) formats.
- Cursor-based pagination (`cursorMark`) and `resumptionToken` handling backed by Symfony Cache.
- Dynamic journal metadata retrieval and caching from the Episciences API for `ListSets` descriptions.
- XSLT stylesheet for human-readable browser rendering with Episciences brand styling.
- CI/CD workflow with GitHub Actions including PHPUnit test suite, PHPStan analysis, Composer audit, and container linting.
- Renovate configuration for dependency updates.

### Changed
- Elevated PHPStan static analysis to level 9 and resolved all type strictness issues.
- Optimized journal metadata fetching with concurrent HTTP requests and connection reuse.

### Fixed
- Enforced OAI-PMH protocol conformance for argument validation and error codes.
- Handled XML serialization failures gracefully and skipped empty sets.
- Documented root path endpoint handling and corrected cURL examples in documentation.
