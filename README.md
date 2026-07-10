# Episciences OAI-PMH Service

[![CI Status](https://github.com/rtournoy/episciences-oai/actions/workflows/ci.yml/badge.svg)](https://github.com/rtournoy/episciences-oai/actions)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.4-blue.svg)](https://packagist.org/packages/rtournoy/episciences-oai)
[![Symfony Version](https://img.shields.io/badge/symfony-8.1-brightgreen.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A modern, OAI-PMH 2.0 microservice for the **Episciences** journal platform. It is a migration of the legacy OAI-PMH server from the legacy Zend Framework 1 application into a modern Symfony microservice.

To achieve maximum performance and isolate the service from the main relational database, this component retrieves pre-rendered XML export formats (Dublin Core, TEI, OpenAIRE, Crossref) directly from **Solr** text fields.

---

## Key Features

*   **Solr-based Serving**: Serves documents directly from the Solr index (`doc_dc`, `doc_tei`, `doc_openaire`, `doc_crossref`) without any SQL queries.
*   **Dynamic Sets Metadata**: Fetches detailed journal configurations (descriptions, publisher, ISSN) directly from the Episciences API for `ListSets` descriptions and caches them for high performance.
*   **Pagination**: Full support for `resumptionToken` pagination utilizing Solr query cursors (`cursorMark`) and Symfony Cache.
*   **XML validation**: Automatic namespace injection and XML sanitization to prevent duplication in payloads.

---

## Requirements

*   **PHP** >= 8.4
*   **Docker** & **Docker Compose**
*   **Composer** (local or via container)

---

## Installation & Setup

### 1. Clone the Repository
Clone the repository alongside the other episciences repositories:

### 2. Configure Environment
Create your local environment overrides from the templates:
```bash
cp .env .env.local
```
* Make sure `SOLR_URL` targets the correct Solr instance (default in local dev environment is `http://episciences-solr:8983/solr`).
* Make sure `EPISCIENCES_API_URL` targets the correct API instance (default is `https://api-dev.episciences.org/`). For local development, `EPISCIENCES_API_HOST` and `EPISCIENCES_API_VERIFY_SSL` are also available to configure routing and skip SSL verification.

### 3. Start the Development Server
This project runs using **FrankenPHP** on the shared `epi-network` reverse-proxied by Traefik.
```bash
docker compose up -d
```

> [!NOTE]
> To access the application locally, you must map the domain `oaing-dev.episciences.org` to your local machine. Add the following line to your `/etc/hosts` file:
> ```text
> 127.0.0.1 oaing-dev.episciences.org
> ```

The OAI-PMH endpoint will be accessible locally via [https://oaing-dev.episciences.org/](https://oaing-dev.episciences.org/).

---

## Local Development commands

We provide a self-documented `Makefile` to simplify local development:

```bash
# Show this help message
make help

# Run the test suite (PHPUnit)
make test

# Run PHPUnit on a specific file
make test target=tests/Controller/OaiPmhControllerTest.php

# Run PHPStan static analysis (Level 6)
make phpstan

# Run PHPStan on a specific file
make phpstan target=src/Controller/OaiPmhController.php

# Clear Symfony cache and the shared var/share cache
make cache-clear
```

---

## CI / CD & Tests

We run continuous integration using **GitHub Actions**. To ensure that your modifications do not break functionality, run tests and static analysis before pushing:

*   **PHPStan** level 6 must compile with **0 errors**:
    ```bash
    make phpstan
    ```
*   **PHPUnit** test suite must pass successfully:
    ```bash
    make test
    ```

---

## Dev Deployment

The service is packaged as a lightweight Docker image built on top of [FrankenPHP](https://frankenphp.dev/):
- The deployment is defined in the [docker-compose.yml](./docker-compose.yml) file.
- It hooks into the shared Traefik instance from [episciences-infrastructure](../episciences-infrastructure) and exposes the endpoint domain `oaing-dev.episciences.org` (in development) or `oai.episciences.org` (in production).
- Production build configures PHP Opcache, preloading, and non-debug runtime optimizations.

