# Episciences OAI-PMH Service - AI Agent Handover Guide

This document contains the architecture, context, and next steps for AI coding assistants working on the `episciences-oai` service.

---

## 1. Project Overview & Context
This project is a migration of the OAI-PMH 2.0 component from the legacy Zend Framework 1 application into a modern Symfony microservice.

### The Core Idea: Solr-Based Metadata Serving
Instead of connecting directly to the main relational database to load papers, authors, citations, and licenses, and rendering XML documents at query time, this service serves pre-rendered XML formats directly from **Solr**. 

---

## 2. Technical Stack
* **Framework**: Symfony 8.1.1 (Skeleton)
* **PHP Version**: PHP 8.4 (via FrankenPHP)
* **Application Server**: [FrankenPHP](https://frankenphp.dev/) (Caddy-based PHP application server running in a single container)
* **Solr Client**: [Solarium](https://www.solarium-project.org/) (`solarium/solarium` package)
* **Docker Network**: Connects to the external Docker network `epi-network` defined in episciences-infrastructure
* **Reverse Proxy**: [Traefik](https://traefik.io/) (exposed via the shared infrastructure, routing domain `oaing-dev.episciences.org` to this container)

---

## 3. Key Configurations & Code Symbols

### Docker & Infrastructure Configuration
* **[Dockerfile](./Dockerfile)**: Defines FrankenPHP base image with `pdo_mysql`, `intl`, `zip`, and `opcache`.
* **[docker-compose.yml](./docker-compose.yml)**: Configures the `web` container. Integrates with the shared Traefik proxy and targets `episciences-solr:8983` on `epi-network`.

### Solr Integration
* **[.env](./.env)**: Defines `SOLR_URL=http://episciences-solr:8983/solr`.
* **[SolariumClientFactory.php](./src/Service/SolariumClientFactory.php)**: Factory that instantiates `Solarium\Client`. 
  * *Note*: Standard Solr URLs have the format `http://host:port/solr`. Because Solarium automatically appends the core/collection name to the path, we set `path => '/'` and `context => 'solr'` in the factory to prevent duplicate `/solr/solr` in requests.
* **[services.yaml](./config/services.yaml)**: Wire `Solarium\Client` using the factory and autowiring.

### OAI-PMH Controller
* **[OaiPmhController.php](./src/Controller/OaiPmhController.php)**: Provides the `/oai` endpoint handling both GET and POST requests.
  * *Symfony 8.1 Note*: The controller uses `Symfony\Component\Routing\Attribute\Route` (the `Annotation` namespace has been removed).

---

## 4. Solr Schema & Document Structure

When querying Solr (collection `episciences`), the following fields are of interest:
* `docid`: The database primary key (`DOCID`) of the paper (e.g. `9998`).
* `revue_code_t`: The code/slug of the journal (e.g. `jdmdh`).
* `publication_date_tdate`: The ISO timestamp of publication (e.g. `2022-09-04T08:00:00Z`).
* **XML Pre-rendered Formats**:
  * `doc_dc`: Pre-rendered Dublin Core XML string.
  * `doc_tei`: Pre-rendered TEI XML string.
  * `doc_openaire`: Pre-rendered OpenAIRE/DataCite XML string.
  * `doc_crossref`: Pre-rendered Crossref XML string.

---

## 5. Current Implementation Status

All six OAI-PMH verbs are fully implemented:
* `Identify`: Responds with the repository identification.
* `ListMetadataFormats`: Lists the 4 supported metadata formats.
* `ListSets`: Lists active journals (using Solr facets) and standard default sets.
* `ListIdentifiers`: Queries Solr and lists matching OAI-PMH headers (with cursor-based pagination).
* `ListRecords`: Queries Solr, lists records with their pre-rendered XML metadata formats, and handles cursor-based pagination.
* `GetRecord`: Queries Solr for a single `docid` and returns the header + pre-rendered XML metadata.

### Features Implemented:
1. **ResumptionToken Pagination**: Uses Solarium cursor marks (`cursorMark`) and caching via Symfony Cache (`Psr\Cache\CacheItemPoolInterface`) to store parameter configurations and resume queries.
2. **ListSets Verb**: Implemented by facet queries on `revue_code_t` and `revue_title_s` combined with default sets.
3. **XML Validation and Namespace Cleanup**: Resolves correct namespace attributes for custom schemas (`tei`, `oai_openaire`, `crossref`) on the wrapper `<metadata>` element.
4. **Robust Error Handling**: Standard-compliant XML error responses for all OAI-PMH error codes (e.g. `badVerb`, `badArgument`, `idDoesNotExist`, `cannotDisseminateFormat`, `noRecordsMatch`, `badResumptionToken`).

---

## 6. Next Steps for Implementation

As the core OAI-PMH component has been migrated, next steps focus on production readiness:
* **Database Connection / Integration (Optional)**: If a more comprehensive journal description is needed for `ListSets` (such as descriptions or ISSNs from `T_REVIEW`), integrate the database connection or consume the legacy JSON API.
* **Production Deployment Configuration**: Configure the redis adapter for Symfony cache in `config/packages/cache.yaml` if container replication is planned in production.
* **Monitoring & Alerts**: Add logging or metrics for OAI-PMH requests and potential Solr connection errors.

---

## 7. Useful Local Commands

* **Start the server**: `docker compose up -d`
* **Tail logs**: `docker compose logs -f`
* **Clear cache**: `docker compose exec web bin/console cache:clear`
* **Check routes**: `docker compose exec web bin/console debug:router`
* **Run tests**: `make test`
* **Run PHPStan**: `make phpstan`
* **Verify OAI Identify**: `curl -i "http://localhost/oai?verb=Identify"`
* **Verify OAI ListRecords**: `curl -i "http://localhost/oai?verb=ListRecords&metadataPrefix=oai_dc"`

---

## 8. Coding & Commit Guidelines

To maintain project consistency and facilitate collaborative development, all contributors (including AI agents) must adhere to the following rules:

### 1. English-Only Language
* **Code Comments & Docblocks**: Must be written in English.
* **Commit Messages & Pull Request Descriptions**: Must be written in English.
* **Exceptions, Logs & UI Messages**: Must be written in English.

### 2. Clear & Functional Commit Messages
Follow the **Conventional Commits** specification (`type(scope): description`):
* `feat(oai)`: for new OAI-PMH features.
* `fix(oai)`: for bug fixes.
* `test(oai)`: for adding or refactoring test cases.
* `docs(oai)`: for documentation changes.
* `chore`: for dependency updates, configuration adjustments, or developer-only tools.
* *Example*: `feat(oai): implement resumptionToken pagination with Solr cursorMark`

### 3. PHP Best Practices
* **Strict Types**: Always begin every PHP file with `declare(strict_types=1);` right after the opening tag.
* **Static Analysis**: Run `make phpstan` before committing to ensure there are no errors at level 6.
* **Unit Tests**: Ensure all tests pass with `make test` before submitting changes.
