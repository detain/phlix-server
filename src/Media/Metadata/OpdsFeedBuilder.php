<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

use DOMDocument;
use DOMElement;
use DOMNode;
use Phlex\Media\Library\ItemRepository;

/**
 * OpdsFeedBuilder builds OPDS 1.2 compliant XML feeds.
 *
 * OPDS (Open Publication Distribution System) is a standard for cataloging
 * and distributing electronic publications. This class generates compliant
 * Atom/OPDS feeds for third-party OPDS clients (Uboiquity, Komga, Kore, etc.).
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Builds OPDS 1.2 compliant catalog feeds
 * @since 0.17.0
 * @see https://opds.io/spec/1.2
 */
class OpdsFeedBuilder
{
    /** OPDS namespace URI */
    public const OPDS_NS = 'http://www.w3.org/2005/Atom';

    /** OPDS catalog namespace URI */
    public const OPDS_CATALOG_NS = 'http://opds-spec.org/2010/catalog';

    /** Dublin Core namespace URI */
    public const DC_NS = 'http://purl.org/dc/elements/1.1/';

    /** @var ItemRepository Repository for media item access */
    private ItemRepository $itemRepo;

    /** @var string Base URL for the OPDS feed */
    private string $baseUrl;

    /**
     * Constructor for OpdsFeedBuilder.
     *
     * @param ItemRepository $itemRepo Repository for media item access
     * @param string $baseUrl Base URL for generating feed links
     *
     * @since 0.17.0
     */
    public function __construct(ItemRepository $itemRepo, string $baseUrl)
    {
        $this->itemRepo = $itemRepo;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Builds the root OPDS catalog feed.
     *
     * The root feed provides navigation links to available libraries
     * and acquisition feeds.
     *
     * @return string OPDS Atom XML feed
     *
     * @since 0.17.0
     */
    public function buildRootFeed(): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Root element
        $feed = $doc->createElementNS(self::OPDS_NS, 'feed');
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opds', self::OPDS_CATALOG_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
        $doc->appendChild($feed);

        // Title
        $this->appendElement($feed, 'title', 'Phlex Library');

        // Updated
        $this->appendElement($feed, 'updated', gmdate('Y-m-d\TH:i:s\Z'));

        // ID
        $this->appendElement($feed, 'id', 'urn:phlex:library:root');

        // Root link
        $selfLink = $this->createLinkElement(
            $doc,
            'self',
            $this->baseUrl . '/opds/v1.2',
            'application/atom+xml;profile=opds-catalog'
        );
        $feed->appendChild($selfLink);

        // Navigation link to libraries
        $navLink = $this->createLinkElement(
            $doc,
            'alternate',
            $this->baseUrl . '/opds/v1.2/libraries',
            'application/atom+xml;profile=opds-catalog;kind=navigation'
        );
        $feed->appendChild($navLink);

        return $doc->saveXML() ?: '';
    }

    /**
     * Builds a navigation feed for library listing.
     *
     * @param array<array<string, mixed>> $libraries Array of library data
     * @return string OPDS Atom XML navigation feed
     *
     * @since 0.17.0
     */
    public function buildNavigationFeed(array $libraries): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Root element
        $feed = $doc->createElementNS(self::OPDS_NS, 'feed');
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opds', self::OPDS_CATALOG_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
        $doc->appendChild($feed);

        // Title
        $this->appendElement($feed, 'title', 'Phlex Libraries');

        // Updated
        $this->appendElement($feed, 'updated', gmdate('Y-m-d\TH:i:s\Z'));

        // ID
        $this->appendElement($feed, 'id', 'urn:phlex:library:libraries');

        // Self link
        $selfLink = $this->createLinkElement(
            $doc,
            'self',
            $this->baseUrl . '/opds/v1.2/libraries',
            'application/atom+xml;profile=opds-catalog;kind=navigation'
        );
        $feed->appendChild($selfLink);

        // Parent link
        $parentLink = $this->createLinkElement(
            $doc,
            'up',
            $this->baseUrl . '/opds/v1.2',
            'application/atom+xml;profile=opds-catalog'
        );
        $feed->appendChild($parentLink);

        // Add library links
        foreach ($libraries as $library) {
            /** @var string $libraryId */
            $libraryId = is_string($library['id'] ?? null) ? $library['id'] : '';
            /** @var string $libraryName */
            $libraryName = is_string($library['name'] ?? null)
                ? htmlspecialchars($library['name'], ENT_XML1, 'UTF-8')
                : 'Unknown';
            /** @var string $libraryType */
            $libraryType = is_string($library['type'] ?? null) ? $library['type'] : '';

            // Only include book libraries in OPDS
            if ($libraryType !== 'book') {
                continue;
            }

            $entry = $doc->createElementNS(self::OPDS_NS, 'entry');
            $feed->appendChild($entry);

            $this->appendElement($entry, 'title', $libraryName);
            $this->appendElement($entry, 'id', 'urn:phlex:library:' . $libraryId);
            $this->appendElement($entry, 'updated', gmdate('Y-m-d\TH:i:s\Z'));

            // Content summary
            $content = $doc->createElement('content');
            $content->setAttribute('type', 'text');
            $content->appendChild($doc->createTextNode('Book library: ' . $libraryName));
            $entry->appendChild($content);

            // Link to acquisition feed
            $acqLink = $this->createLinkElement(
                $doc,
                'subsection',
                $this->baseUrl . '/opds/v1.2/libraries/' . $libraryId,
                'application/atom+xml;profile=opds-catalog;kind=acquisition'
            );
            $entry->appendChild($acqLink);
        }

        return $doc->saveXML() ?: '';
    }

    /**
     * Builds an acquisition feed for books in a library.
     *
     * @param string $libraryId Library identifier
     * @param int $limit Maximum number of items to return
     * @param int $offset Pagination offset
     * @param int|null $total Total number of items (for pagination)
     * @return string OPDS Atom XML acquisition feed
     *
     * @since 0.17.0
     */
    public function buildAcquisitionFeed(
        string $libraryId,
        int $limit = 50,
        int $offset = 0,
        ?int $total = null
    ): string {
        // Fetch items from repository
        $items = $this->itemRepo->getByLibrary($libraryId, $limit, $offset);
        $books = array_filter($items, fn($item) => ($item['type'] ?? '') === 'book');

        if ($total === null) {
            $total = count($books);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Root element
        $feed = $doc->createElementNS(self::OPDS_NS, 'feed');
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:opds', self::OPDS_CATALOG_NS);
        $feed->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', self::DC_NS);
        $doc->appendChild($feed);

        // Title
        $this->appendElement($feed, 'title', 'Books');

        // Updated
        $this->appendElement($feed, 'updated', gmdate('Y-m-d\TH:i:s\Z'));

        // ID
        $this->appendElement($feed, 'id', 'urn:phlex:library:' . $libraryId . ':books');

        // Self link
        $selfLink = $this->createLinkElement(
            $doc,
            'self',
            $this->baseUrl . '/opds/v1.2/libraries/' . $libraryId
            . '?offset=' . $offset . '&limit=' . $limit,
            'application/atom+xml;profile=opds-catalog;kind=acquisition'
        );
        $feed->appendChild($selfLink);

        // Parent link
        $parentLink = $this->createLinkElement(
            $doc,
            'up',
            $this->baseUrl . '/opds/v1.2/libraries',
            'application/atom+xml;profile=opds-catalog;kind=navigation'
        );
        $feed->appendChild($parentLink);

        // Add pagination links if needed
        if ($offset > 0) {
            $prevOffset = max(0, $offset - $limit);
            $prevLink = $this->createLinkElement(
                $doc,
                'previous',
                $this->baseUrl . '/opds/v1.2/libraries/' . $libraryId
                . '?offset=' . $prevOffset . '&limit=' . $limit,
                'application/atom+xml;profile=opds-catalog;kind=acquisition'
            );
            $feed->appendChild($prevLink);
        }

        if (($offset + count($books)) < $total) {
            $nextOffset = $offset + $limit;
            $nextLink = $this->createLinkElement(
                $doc,
                'next',
                $this->baseUrl . '/opds/v1.2/libraries/' . $libraryId
                . '?offset=' . $nextOffset . '&limit=' . $limit,
                'application/atom+xml;profile=opds-catalog;kind=acquisition'
            );
            $feed->appendChild($nextLink);
        }

        // Add book entries
        foreach ($books as $book) {
            $entry = $this->buildEntry($book);
            $feed->appendChild($doc->importNode($entry, true));
        }

        return $doc->saveXML() ?: '';
    }

    /**
     * Builds a single OPDS entry for a book.
     *
     * @param array<string, mixed> $book Media item data
     * @return DOMElement OPDS entry element
     *
     * @since 0.17.0
     */
    public function buildEntry(array $book): DOMElement
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        // Create entry in a detached document for the import
        $entry = $doc->createElementNS(self::OPDS_NS, 'entry');

        // Get metadata - ensure proper typing
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($book['metadata'] ?? null) ? $book['metadata'] : [];

        $bookName = is_string($book['name'] ?? null) ? $book['name'] : 'Unknown';
        $bookId = is_string($book['id'] ?? null) ? $book['id'] : '';

        $titleValue = is_string($metadata['title'] ?? null) ? $metadata['title'] : $bookName;
        $authorValue = is_string($metadata['author'] ?? null) ? $metadata['author'] : 'Unknown Author';

        $title = htmlspecialchars($titleValue, ENT_XML1, 'UTF-8');
        $author = htmlspecialchars($authorValue, ENT_XML1, 'UTF-8');

        // Title (dc:title)
        $titleEl = $doc->createElementNS(self::DC_NS, 'dc:title');
        $titleEl->appendChild($doc->createTextNode($titleValue));
        $entry->appendChild($titleEl);

        // ID - use ISBN or URN
        $isbnValue = is_string($metadata['isbn'] ?? null) ? $metadata['isbn'] : null;
        $identifier = $isbnValue !== null
            ? $isbnValue
            : ('urn:phlex:book:' . $bookId);
        $idEl = $doc->createElementNS(self::OPDS_NS, 'id');
        $idEl->appendChild($doc->createTextNode($identifier));
        $entry->appendChild($idEl);

        // Updated
        $updatedEl = $doc->createElementNS(self::OPDS_NS, 'updated');
        $updatedEl->appendChild($doc->createTextNode(gmdate('Y-m-d\TH:i:s\Z')));
        $entry->appendChild($updatedEl);

        // Author (dc:creator)
        if (!empty($metadata['author']) && is_string($metadata['author'])) {
            $authorEl = $doc->createElementNS(self::DC_NS, 'dc:creator');
            $authorEl->appendChild($doc->createTextNode($metadata['author']));
            $entry->appendChild($authorEl);
        }

        // Publisher (dc:publisher)
        if (!empty($metadata['publisher']) && is_string($metadata['publisher'])) {
            $pubEl = $doc->createElementNS(self::DC_NS, 'dc:publisher');
            $pubEl->appendChild($doc->createTextNode($metadata['publisher']));
            $entry->appendChild($pubEl);
        }

        // Language (dc:language)
        if (!empty($metadata['language']) && is_string($metadata['language'])) {
            $langEl = $doc->createElementNS(self::DC_NS, 'dc:language');
            $langEl->appendChild($doc->createTextNode($metadata['language']));
            $entry->appendChild($langEl);
        }

        // Description (dc:description)
        if (!empty($metadata['description']) && is_string($metadata['description'])) {
            $descEl = $doc->createElementNS(self::DC_NS, 'dc:description');
            $descEl->appendChild($doc->createTextNode($metadata['description']));
            $entry->appendChild($descEl);
        }

        // Summary/Content
        $content = $doc->createElement('content');
        $content->setAttribute('type', 'text');
        $summary = $title;
        if (!empty($metadata['author']) && is_string($metadata['author'])) {
            $summary .= ' by ' . htmlspecialchars($metadata['author'], ENT_XML1, 'UTF-8');
        }
        $content->appendChild($doc->createTextNode($summary));
        $entry->appendChild($content);

        // Cover link (alternate relation)
        $coverLink = $this->createLinkElement(
            $doc,
            'alternate',
            $this->baseUrl . '/opds/v1.2/books/' . $bookId . '/cover',
            'image/jpeg'
        );
        $coverLink->setAttribute('type', 'image/jpeg');
        $coverLink->setAttribute('title', 'Cover');
        $entry->appendChild($coverLink);

        // Acquisition link (download)
        $bookPath = is_string($book['path'] ?? null) ? $book['path'] : '';
        $downloadLink = $this->createLinkElement(
            $doc,
            'acquisition',
            $this->baseUrl . '/opds/v1.2/books/' . $bookId . '/download',
            $this->getBookMediaType($bookPath)
        );
        $downloadLink->setAttribute('type', $this->getBookMediaType($bookPath));
        $entry->appendChild($downloadLink);

        return $entry;
    }

    /**
     * Gets the media type for a book file.
     *
     * @param string $path File path
     * @return string MIME type
     *
     * @since 0.17.0
     */
    private function getBookMediaType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'cbz' => 'application/vnd.comicbook+zip',
            default => 'application/octet-stream',
        };
    }

    /**
     * Appends a simple text element to a parent node.
     *
     * @param DOMElement $parent Parent element
     * @param string $tagName Element tag name
     * @param string $text Text content
     *
     * @since 0.17.0
     */
    private function appendElement(DOMElement $parent, string $tagName, string $text): void
    {
        $doc = $parent->ownerDocument;
        if ($doc === null) {
            return;
        }
        $el = $doc->createElementNS(self::OPDS_NS, $tagName);
        $el->appendChild($doc->createTextNode($text));
        $parent->appendChild($el);
    }

    /**
     * Creates an OPDS link element.
     *
     * @param DOMDocument $doc Document for creating elements
     * @param string $rel Link relationship
     * @param string $href Link URL
     * @param string $type Link MIME type
     * @return DOMElement Created link element
     *
     * @since 0.17.0
     */
    private function createLinkElement(
        DOMDocument $doc,
        string $rel,
        string $href,
        string $type
    ): DOMElement {
        $link = $doc->createElementNS(self::OPDS_NS, 'link');
        $link->setAttribute('rel', $rel);
        $link->setAttribute('href', $href);
        $link->setAttribute('type', $type);
        return $link;
    }
}
