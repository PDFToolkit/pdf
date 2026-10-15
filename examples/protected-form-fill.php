<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\FileStructure;
use PDF\Security\CertificateChain;
use PDF\Security\OpenSslIdentity;
use PDF\Security\OpenSslSignatureBackend;
use PDF\Security\X509Certificate;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\HexadecimalString;

require dirname(__DIR__) . '/vendor/autoload.php';

// This public test credential proves the signature mathematics, not PKI trust.
$fixtures = dirname(__DIR__) . '/tests/Fixtures/signing/';
$certificate = file_get_contents($fixtures . 'signer.der');
$key = file_get_contents($fixtures . 'signer-private-key.pem');
if ($certificate === false || $key === false) {
    throw new RuntimeException('The public signing fixtures are unavailable.');
}
$backend = new OpenSslSignatureBackend(new OpenSslIdentity(new CertificateChain(new X509Certificate(new HexadecimalString($certificate))), $key));
$entry = static fn (string $key, \PDF\Syntax\FileStructure\BasicType $value): Entry => new Entry(new NameType($key), $value);
$rectangle = static fn (int $x0, int $y0, int $x1, int $y1): Rectangle => new Rectangle(new IntegerType($x0), new IntegerType($y0), new IntegerType($x1), new IntegerType($y1));
$font = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary($entry('Font', new DictionaryType($entry('F', new IndirectReference($font)))));

// ISO1 physical p444: only /Tx BMC...EMC changes. The frame stays byte-identical;
// q/Q and complete text/path scopes prevent graphics state leaking out of /Tx.
$appearance = static fn (string $text): \PDF\Document\Forms\FormAppearanceStream => new \PDF\Document\Forms\FormAppearanceStream(
    new \PDF\Syntax\ContentStreamsResources\ContentStreams(
        new \PDF\Syntax\ContentStreamsResources\Operators\SetStrokingGray(new \PDF\Syntax\Object\NumericType(0.6)),
        new \PDF\Syntax\ContentStreamsResources\Operators\SetLineWidth(new IntegerType(1)),
        new \PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle(new \PDF\Syntax\Object\NumericType(0.5), new \PDF\Syntax\Object\NumericType(0.5), new IntegerType(349), new IntegerType(31)),
        new \PDF\Syntax\ContentStreamsResources\Operators\StrokePath(),
        new \PDF\Syntax\ContentStreamsResources\Operators\BeginMarkedContent(new NameType('Tx')),
        new \PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState(),
        new \PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject(),
        new SetTextFontAndSize(new NameType('F'), new IntegerType(14)),
        new \PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition(new IntegerType(8), new IntegerType(10)),
        new \PDF\Syntax\ContentStreamsResources\Operators\ShowText(new \PDF\Syntax\Object\StringObject\LiteralString($text)),
        new \PDF\Syntax\ContentStreamsResources\Operators\EndTextObject(),
        new \PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState(),
        new \PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent(),
    ),
    $rectangle(0, 0, 350, 32),
    $resources,
);
$field = new TextField(new PartialFieldName(new TextString('Response')), value: new TextString('Pending'));
$locked = new TextField(new PartialFieldName(new TextString('Reference')), value: new TextString('CASE-2026-010'));
$widget = new WidgetAnnotation($rectangle(48, 570, 398, 602), new WidgetAppearance($appearance('Pending')), entries: $entry('F', new IntegerType(4)));
$field->addWidget($widget);
$author = new SignatureFieldSigning(
    $backend,
    new PartialFieldName(new TextString('Certification')),
    new SignatureCapacity(8192),
    new SignatureSigningIntent(certification: DocMdpPermissions::FormFillingTemplatesAndSigning),
    lock: new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('Reference')))
);
$form = new AcroForm((new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('F'), new IntegerType(14))), $resources))
    ->addRoot($author->field)->addRoot($locked)->addRoot($field));
$contents = new StreamObject("BT /F 22 Tf 48 760 Td (Fill a certified document) Tj ET\n"
    . "BT /F 12 Tf 48 720 Td (DocMDP P2 allows filling; FieldMDP locks Reference.) Tj ET\n"
    . "BT /F 12 Tf 48 680 Td (The first revision is signed before Response is changed.) Tj ET\n"
    . "BT /F 12 Tf 48 624 Td (Response - filled in the second revision:) Tj ET\n"
    . "BT /F 11 Tf 48 520 Td (Original page content, resources and signed bytes remain intact.) Tj ET\n"
    . "BT /F 11 Tf 48 490 Td (The appearance uses a fresh Form object; the old one remains.) Tj ET\n"
    . "BT /F 10 Tf 48 80 Td (Public test certificate. No certificate trust or human review claimed.) Tj ET\n");
$page = new PageObject($rectangle(0, 0, 595, 842), $resources, $contents, $entry('Annots', new ArrayType(new IndirectReference($widget))));
$widget->bindToPage($page);
$permissions = $author->certificationPermissions() ?? throw new LogicException('The author signature declares DocMDP certification.');
$catalog = new DocumentCatalog((new PageTreeNode())->addKid($page), $entry('AcroForm', new IndirectReference($form)), $entry('Perms', $permissions));
$author->bindCatalog(new IndirectReference($catalog));
$signed = $author->sign((new SignatureWriter())->prepare(new FileStructure(new FileHeader('1.7'), new FileBody($catalog)), $author->signature));

// The common writer proves the complete emitted delta, including every object
// not listed here. P2 does not authorize changing arbitrary page content.
$fieldRecord = $signed->revision->binding($field);
$widgetRecord = $signed->revision->binding($widget);
$fieldDictionary = $fieldRecord->dictionary ?? throw new LogicException('The signed field retains its dictionary.');
$widgetDictionary = $widgetRecord->dictionary ?? throw new LogicException('The signed widget retains its dictionary.');
$update = new IncrementalUpdate($signed->revision, new FileBody(
    new IndirectObject($fieldDictionary->with($entry('V', new TextString('Reviewed'))), $fieldRecord->identifier),
    new IndirectObject($widgetDictionary->with($entry('AP', new DictionaryType($entry('N', new IndirectReference($appearance('Reviewed')))))), $widgetRecord->identifier),
));
$filled = $update->toRevision();
if (!str_starts_with($filled->bytes, $signed->revision->bytes)) {
    throw new LogicException('The original signed revision must remain an exact prefix.');
}
$path = $argv[1] ?? __DIR__ . '/protected-form-fill.pdf';
$filled->writeTo($path);
echo 'PDF written to ' . $path . PHP_EOL;
