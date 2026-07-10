# Episciences OAI-PMH Service

[![CI Status](https://github.com/rtournoy/episciences-oai/actions/workflows/ci.yml/badge.svg)](https://github.com/rtournoy/episciences-oai/actions)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.4-blue.svg)](https://packagist.org/packages/rtournoy/episciences-oai)
[![Symfony Version](https://img.shields.io/badge/symfony-8.1-brightgreen.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A modern, OAI-PMH 2.0 microservice for the **Episciences** journal platform. It is a migration of the legacy OAI-PMH server from the legacy Zend Framework 1 application into a modern Symfony microservice.

To achieve maximum performance and isolate the service from the main relational database, this component retrieves pre-rendered XML export formats (Dublin Core, TEI, OpenAIRE, Crossref) directly from **Solr** text fields.

---

## 🚀 Key Features

*   **Solr-based Serving**: Serves documents directly from the Solr index (`doc_dc`, `doc_tei`, `doc_openaire`, `doc_crossref`) without any SQL queries.
*   **Pagination**: Full support for `resumptionToken` pagination utilizing Solr query cursors (`cursorMark`) and Symfony Cache.
*   **XML validation**: Automatic namespace injection and XML sanitization to prevent duplication in payloads.

---

## 🛠️ Requirements

*   **PHP** >= 8.4
*   **Docker** & **Docker Compose**
*   **Composer** (local or via container)

---

## ⚙️ Installation & Setup

### 1. Clone the Repository
Clone the repository alongside the other episciences repositories:
```bash
git clone https://github.com/rtournoy/episciences-oai.git
cd episciences-oai
```

### 2. Configure Environment
Create your local environment overrides from the templates:
```bash
cp .env .env.local
```
Make sure `SOLR_URL` targets the correct Solr instance (default in local dev environment is `http://episciences-solr:8983/solr`).

### 3. Start the Development Server
This project runs using **FrankenPHP** on the shared `epi-network` reverse-proxied by Traefik.
```bash
docker compose up -d
```
The OAI-PMH endpoint will be accessible locally via [https://oaing-dev.episciences.org/](https://oaing-dev.episciences.org/).

---

## 📖 Local Development commands

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
```

---

## 🧪 CI / CD & Tests

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

## 🚢 Deployment

The service is packaged as a lightweight Docker image built on top of [FrankenPHP](https://frankenphp.dev/):
- The deployment is defined in the [docker-compose.yml](./docker-compose.yml) file.
- It hooks into the shared Traefik instance from [episciences-infrastructure](../episciences-infrastructure) and exposes the endpoint domain `oaing-dev.episciences.org` (in development) or `oai.episciences.org` (in production).
- Production build configures PHP Opcache, preloading, and non-debug runtime optimizations.

---

## 🤝 Contribution Guidelines

1.  **Language**: All code comments, docblocks, logs, commit messages, and documentation must be written in **English**.
2.  **Strict Types**: Every PHP file must declare strict types at the very beginning of the file:
    ```php
    <?php
    declare(strict_types=1);
    ```
3.  **Commit Messages**: Follow the **Conventional Commits** specification (`type(scope): description`). Example:
    ```bash
    git commit -m "feat(oai): add support for ListSets using Solr facets"
    ```
