<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Supported OAI-PMH metadata formats and their Solr/XML mappings.
 */
enum MetadataFormat: string
{
    case OAI_DC = 'oai_dc';
    case TEI = 'tei';
    case OAI_OPENAIRE = 'oai_openaire';
    case CROSSREF = 'crossref';

    public function solrField(): string
    {
        return match ($this) {
            self::OAI_DC => 'doc_dc',
            self::TEI => 'doc_tei',
            self::OAI_OPENAIRE => 'doc_openaire',
            self::CROSSREF => 'doc_crossref',
        };
    }

    public function schemaUrl(): string
    {
        return match ($this) {
            self::OAI_DC => 'https://www.openarchives.org/OAI/2.0/oai_dc.xsd',
            self::TEI => 'https://api.archives-ouvertes.fr/documents/aofr.xsd',
            self::OAI_OPENAIRE => 'https://www.openaire.eu/schema/repo-lit/4.0/openaire.xsd',
            self::CROSSREF => 'https://www.crossref.org/schemas/crossref5.3.1.xsd',
        };
    }

    public function namespaceUri(): string
    {
        return match ($this) {
            self::OAI_DC => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
            self::TEI => 'https://hal.archives-ouvertes.fr/',
            self::OAI_OPENAIRE => 'http://namespace.openaire.eu/schema/oaire/',
            self::CROSSREF => 'http://www.crossref.org/schema/5.3.1',
        };
    }

    /**
     * Namespace declarations to add on the wrapper <metadata> element,
     * required because the pre-rendered XML relies on these prefixes.
     *
     * @return array<string, string> prefix => namespace URI
     */
    public function wrapperNamespaces(): array
    {
        return match ($this) {
            self::OAI_DC => [],
            self::TEI => ['tei' => 'http://www.tei-c.org/ns/1.0'],
            self::OAI_OPENAIRE => ['datacite' => 'http://datacite.org/schema/kernel-4'],
            self::CROSSREF => ['crossref' => $this->namespaceUri()],
        };
    }
}
