<?php

declare(strict_types=1);

use PDF\Document\Collections\Collection;
use PDF\Document\Collections\CollectionView;
use PDF\Document\Files as Files;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\FileStructure;
use PDF\Security as Security;
use PDF\Serialization\FileWriter;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;
use PDF\Syntax\DataStructure\Date;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Filters\FlateDecode;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StringObject\HexadecimalString;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// The content helper creates ordinary explicit PDF page/font/operator objects.
$createPage = static function (string ...$lines): PageTreeNode {
    // Each explicit page construction supplies complete PDF 2.0 font metrics.
    $font = require __DIR__ . '/fixtures/pdf20-courier-font.php';
    $resources = new ResourceDictionary(new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($font)))));
    $operators = [];
    foreach (array_values($lines) as $index => $line) {
        $operators[] = new Op\BeginTextObject();
        $operators[] = new Op\SetTextFontAndSize(new NameType('F1'), new NumericType($index === 0 ? 20 : 12));
        $operators[] = new Op\MoveTextPosition(new NumericType(48), new NumericType(780 - 32 * $index));
        $operators[] = new Op\ShowText(new LiteralString($line));
        $operators[] = new Op\EndTextObject();
    }
    return (new PageTreeNode())->addKid(new PageObject(new Rectangle(new NumericType(0), new NumericType(0), new NumericType(595), new NumericType(842)), $resources, new \PDF\Syntax\Object\StreamObject(new ContentStreams(...$operators))));
};
// This is a real encrypted PDF, not an EP label on clear bytes. Demo passwords
// are intentionally public. File keys, salts and IVs come from SystemRandomSource.
$random = new Security\SystemRandomSource();
$security = (new Security\ModernStandardSecurityHandler(new Security\OpenSslAes256Backend(), $random))->create(new Security\PreparedPassword('demo-reader'), new Security\PreparedPassword('demo-owner'), new Security\Pdf20Permissions(-4), new Security\EncryptMetadata(true));
$identifier = new HexadecimalString($random->bytes(16));
$inner = new FileStructure(new FileHeader('2.0'), new FileBody(new DocumentCatalog($createPage('Encrypted payload', 'This text becomes available after password authentication.'))), trailerEntries: new DictionaryType(new Entry(new NameType('ID'), new ArrayType($identifier, $identifier))));
$encryptedBytes = (new FileWriter(encryption: $security))->serialize($inner);
$payload = new Files\EmbeddedFileStream($encryptedBytes, new NameType('application/pdf'), modificationDate: new Date(new DateTimeImmutable('2026-10-14T00:00:00Z')), filters: new FlateDecode());
$specification = new Files\FileSpecification(new LiteralString('payload.pdf'), new Files\FileSpecificationOptions(embedded: new Files\EmbeddedFileBindings(new Files\EmbeddedFileBinding(Files\EmbeddedFileKey::File, $payload))), new Files\AssociatedFileRelationship(Files\AFRelationship::EncryptedPayload), encryptedPayload: new Files\EncryptedPayload(new NameType('Standard')));
$instructions = new Files\FileSpecification(new LiteralString('instructions.txt'), new Files\FileSpecificationOptions(embedded: new Files\EmbeddedFileBindings(new Files\EmbeddedFileBinding(Files\EmbeddedFileKey::File, new Files\EmbeddedFileStream('Open payload.pdf with the demonstration password demo-reader.', new NameType('text/plain'))))));
$attachments = new Files\EmbeddedFiles(new Files\EmbeddedFileAttachment(new LiteralString('payload.pdf'), $specification), new Files\EmbeddedFileAttachment(new LiteralString('instructions.txt'), $instructions));
$collection = new Collection(view: CollectionView::Hidden, initialDocument: new LiteralString('payload.pdf'));
// ISO 32000-2 7.6.7 requires a catalog AF association and a hidden collection
// selecting that payload. EC3 #529 permits other named attachments, such as
// these instructions. The wrapper itself stays unencrypted.
$catalog = new DocumentCatalog($createPage('Encrypted PDF payload', 'This unencrypted wrapper contains one encrypted PDF.', 'Extract payload.pdf from the attachments, then open it.', 'Demonstration reader password: demo-reader', 'The payload uses the Standard security handler with AES-256.', 'Some viewers open the selected payload automatically.'), new Entry(new NameType('Names'), new DictionaryType($attachments->nameDictionaryEntry())), new Files\AssociatedFilesEntry(new Files\AssociatedFiles(new IndirectReference($specification))), $collection->catalogEntry());
$output = $argv[1] ?? __DIR__ . '/pdf20-encrypted-payload.pdf';
(new FileStructure(new FileHeader('2.0'), new FileBody($catalog)))->writeTo($output);
echo $output . PHP_EOL;
