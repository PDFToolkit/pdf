<?php

declare(strict_types=1);

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Forms\AcroForm;
use PDF\Document\Forms\AppearanceState;
use PDF\Document\Forms\AppearanceStates;
use PDF\Document\Forms\ButtonField;
use PDF\Document\Forms\ButtonFieldFlag;
use PDF\Document\Forms\ButtonFieldFlags;
use PDF\Document\Forms\ChoiceField;
use PDF\Document\Forms\ChoiceFieldValue;
use PDF\Document\Forms\ChoiceOption;
use PDF\Document\Forms\ChoiceOptions;
use PDF\Document\Forms\ChoiceValueDomain;
use PDF\Document\Forms\CommonFieldFlag;
use PDF\Document\Forms\DefaultAppearance;
use PDF\Document\Forms\FieldTree;
use PDF\Document\Forms\FormAppearanceStream;
use PDF\Document\Forms\PartialFieldName;
use PDF\Document\Forms\TextField;
use PDF\Document\Forms\TextFieldFlags;
use PDF\Document\Forms\WidgetAnnotation;
use PDF\Document\Forms\WidgetAppearance;
use PDF\Document\ResourceDictionary;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\SignatureFieldSigning;
use PDF\Document\Signatures\SignatureSigningIntent;
use PDF\Document\Signatures\UsageRightsAuthority;
use PDF\Document\Signatures\UsageRightsAuthorization;
use PDF\Document\Signatures\VerifiedRevisionSignature;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRight;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\FileStructure;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\FileStructure\CrossReferenceFormat;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\FileStructure\FileRevision;
use PDF\Syntax\FileStructure\IncrementalUpdate;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\StreamObject;
use PDF\Tests\Support\ProtectedRevisionFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$output = $argv[1] ?? throw new InvalidArgumentException('Pass an output directory.');
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Cannot create fixture directory.');
}
$rejected = [];
$forms = [];
$extendedText = [];
$annotations = [];
$usageRights = [];
$refuse = static function (string $name, IncrementalUpdate $update) use (&$rejected): void {
    try {
        $update->toRevision();
    } catch (LogicException) {
        $rejected[$name] = true;
        return;
    }
    throw new RuntimeException('Expected native rejection: ' . $name);
};
foreach (DocMdpPermissions::cases() as $permission) {
    $fixture = new ProtectedRevisionFixture($permission);
    $fixture->revision->writeTo($output . '/core-p' . $permission->value . '.pdf');
    foreach (['lang', 'page', 'content', 'remove-perms', 'remove-form', 'root', 'trailer', 'release', 'field-value', 'field-name', 'fake-xref', 'orphan'] as $attack) {
        if ($attack === 'field-value' && $permission !== DocMdpPermissions::NoChanges) {
            $filled = $fixture->mutation($attack)->toRevision();
            if (!str_starts_with($filled->bytes, $fixture->revision->bytes)) {
                throw new RuntimeException('A form fill must preserve the entire signed prefix.');
            }
            $filled->writeTo($output . '/core-p' . $permission->value . '-form-fill.pdf');
            continue;
        }
        $refuse('P' . $permission->value . ':' . $attack, $fixture->mutation($attack));
    }
    foreach (['lang', 'page', 'content', 'field-value', 'remove-perms', 'fake-xref'] as $attack) {
        [$timestamp] = $fixture->signing(timestamp: true, attack: $attack);
        $refuse('P' . $permission->value . ':timestamp:' . $attack, $timestamp);
    }
    if ($permission !== DocMdpPermissions::NoChanges) {
        [$update, $pending] = $fixture->signing();
        (new PreparedSignature($update->toRevision(), $pending))->signWithPolicy($fixture->backend)->revision->writeTo($output . '/core-p' . $permission->value . '-approval.pdf');
        [$hidden] = $fixture->signing(attack: 'content');
        $refuse('P' . $permission->value . ':approval:content', $hidden);
        $fieldFill = $fixture->mutation('field-value');
        $refuse('P' . $permission->value . ':form-fill:content', new IncrementalUpdate(
            $fixture->revision,
            new FileBody(...[...$fieldFill->body->objects(), new IndirectObject(new StreamObject('0 0 30 30 re f'), $fixture->revision->binding($fixture->content)->identifier)])
        ));
    }
    if ($permission === DocMdpPermissions::NoChanges) {
        $fixture->dss()->toRevision()->writeTo($output . '/core-p1-dss.pdf');
    }
}
$locked = new ProtectedRevisionFixture(null, new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('name'))));
$locked->revision->writeTo($output . '/core-fieldmdp.pdf');
$refuse('FieldMDP:locked-value', $locked->mutation('field-value'));
$refuse('FieldMDP:hidden-form', $locked->mutation('remove-form'));
$backend = $locked->backend;
// Independently inspectable form sources, including an appearance also used by the page.
$formFixture = static function (string $kind, DocMdpPermissions $permission, CrossReferenceFormat $format, string $version, bool $readOnly = false) use ($backend): array {
    $author = new SignatureFieldSigning($backend, new PartialFieldName(new TextString('author')), new SignatureCapacity(8192), new SignatureSigningIntent(certification: $permission));
    $name = new PartialFieldName(new TextString('value'));
    $field = match ($kind) {
        'text' => new TextField($name, $readOnly ? new TextFieldFlags(CommonFieldFlag::ReadOnly) : null, new TextString('original')),
        'file-select' => new TextField($name, new TextFieldFlags(\PDF\Document\Forms\TextFieldFlag::FileSelect), new TextString('original')),
        'richtext' => new TextField($name, new TextFieldFlags(\PDF\Document\Forms\TextFieldFlag::RichText), new TextString('original'), entries: new Entry(new NameType('RV'), new TextString('<body><p>original</p></body>'))),
        'choice' => new ChoiceField($name, new ChoiceOptions(new ChoiceOption(new TextString('original')), new ChoiceOption(new TextString('changed'))), value: new ChoiceFieldValue(ChoiceValueDomain::ExportValues, new TextString('original'))),
        'checkbox' => new ButtonField($name, value: new NameType('Off')),
        'radio' => new ButtonField($name, new ButtonFieldFlags(ButtonFieldFlag::Radio), new NameType('Off')),
        default => throw new InvalidArgumentException('Unknown oracle field kind.'),
    };
    $widgets = [];
    $sharedAppearance = null;
    if ($kind === 'checkbox' || $kind === 'radio') {
        foreach ($kind === 'radio' ? ['A', 'B'] : ['Yes'] as $index => $on) {
            $bbox = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20));
            $offAppearance = new FormAppearanceStream(new ContentStreams(), $bbox, new ResourceDictionary());
            $onAppearance = new FormAppearanceStream(new ContentStreams(
                new SetNonstrokingRGBColor(new IntegerType(0), new IntegerType(0), new IntegerType(1)),
                new AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20)),
                new FillPathNonzero(),
            ), $bbox, new ResourceDictionary());
            $sharedAppearance ??= $onAppearance;
            $widget = new WidgetAnnotation(
                new Rectangle(new IntegerType(20 + $index * 30), new IntegerType(20), new IntegerType(40 + $index * 30), new IntegerType(40)),
                new WidgetAppearance(new AppearanceStates(new AppearanceState(new NameType('Off'), $offAppearance), new AppearanceState(new NameType($on), $onAppearance))),
                new NameType('Off'),
            );
            $field->addWidget($widget);
            $widgets[] = $widget;
        }
    }
    $resources = $sharedAppearance === null ? new ResourceDictionary() : new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Shared'), new IndirectReference($sharedAppearance)))));
    $content = new StreamObject($sharedAppearance === null ? "q\nQ\n" : "q /Shared Do Q\n");
    $pageEntries = $widgets === [] ? [] : [new Entry(new NameType('Annots'), new ArrayType(...array_map(static fn (WidgetAnnotation $widget): IndirectReference => new IndirectReference($widget), $widgets)))];
    $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), $resources, $content, ...$pageEntries);
    foreach ($widgets as $widget) {
        $widget->bindToPage($page);
    }
    $font = require dirname(__DIR__, 2) . '/examples/fixtures/pdf20-courier-font.php';
    $defaults = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($font)))));
    $form = new AcroForm((new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('F'), new IntegerType(12))), $defaults))->addRoot($author->field)->addRoot($field));
    $catalog = new DocumentCatalog(
        (new PageTreeNode())->addKid($page),
        new Entry(new NameType('AcroForm'), new IndirectReference($form)),
        new Entry(new NameType('Perms'), $author->certificationPermissions()),
    );
    $author->bindCatalog(new IndirectReference($catalog));
    $prepared = (new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($catalog), crossReferenceFormat: $format), $author->signature);
    $revision = $author->sign($prepared)->revision;
    $record = $revision->binding($field);
    $value = in_array($kind, ['text', 'choice', 'file-select', 'richtext'], true) ? new TextString('changed') : new NameType($kind === 'radio' ? 'A' : 'Yes');
    $replacement = $record->dictionary->with(new Entry(new NameType('V'), $value));
    if ($kind === 'richtext') {
        $replacement = $replacement->with(new Entry(new NameType('RV'), new TextString('<body><p><b>changed</b></p></body>')));
    }
    $objects = [new IndirectObject($replacement, $record->identifier)];
    if ($widgets !== []) {
        $widget = $revision->binding($widgets[0]);
        $objects[] = new IndirectObject($widget->dictionary->with(new Entry(new NameType('AS'), $value)), $widget->identifier);
    }
    return [$revision, $objects, $sharedAppearance];
};
foreach ([DocMdpPermissions::FormFillingTemplatesAndSigning, DocMdpPermissions::FormsTemplatesSigningAndAnnotations] as $permission) {
    foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
        foreach (['1.7', '2.0'] as $version) {
            foreach (['text', 'choice', 'checkbox', 'radio'] as $kind) {
                [$revision, $objects, $sharedAppearance] = $formFixture($kind, $permission, $format, $version);
                $stem = 'form-p' . $permission->value . '-' . strtolower($format->name) . '-' . str_replace('.', '', $version) . '-' . $kind;
                $revision->writeTo($output . '/' . $stem . '.pdf');
                $filled = (new IncrementalUpdate($revision, new FileBody(...$objects)))->toRevision();
                if (!str_starts_with($filled->bytes, $revision->bytes)) {
                    throw new RuntimeException('A native form fill must preserve the signed prefix.');
                }
                $filled->writeTo($output . '/' . $stem . '-filled.pdf');
                $forms[] = ['source' => $stem . '.pdf', 'filled' => $stem . '-filled.pdf', 'kind' => $kind, 'permission' => $permission->value, 'format' => $format->name, 'version' => $version];
                if ($sharedAppearance !== null) {
                    $appearance = $revision->binding($sharedAppearance);
                    $changed = new IndirectObject(new StreamObject('1 0 0 rg 0 0 20 20 re f', $appearance->dictionary->without('Length')), $appearance->identifier);
                    $refuse($stem . ':shared-appearance', new IncrementalUpdate($revision, new FileBody(...[...$objects, $changed])));
                }
            }
        }
    }
    [$revision, $objects] = $formFixture('text', $permission, CrossReferenceFormat::Table, '2.0', readOnly: true);
    $revision->writeTo($output . '/core-p' . $permission->value . '-readonly.pdf');
    $refuse('P' . $permission->value . ':readonly-value', new IncrementalUpdate($revision, new FileBody(...$objects)));
}
$richTextFilling = new \PDF\Document\Signatures\RichTextFilling(new \PDF\Document\Structure\WtPdfXmlRichTextParser());
foreach ([DocMdpPermissions::FormFillingTemplatesAndSigning, DocMdpPermissions::FormsTemplatesSigningAndAnnotations] as $permission) {
    foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
        foreach (['1.7', '2.0'] as $version) {
            foreach (['richtext', 'file-select'] as $kind) {
                [$revision, $objects] = $formFixture($kind, $permission, $format, $version);
                $stem = 'extended-p' . $permission->value . '-' . strtolower($format->name) . '-' . str_replace('.', '', $version) . '-' . $kind;
                $revision->writeTo($output . '/' . $stem . '.pdf');
                $filled = (new IncrementalUpdate($revision, new FileBody(...$objects), null, null, ...($kind === 'richtext' ? [$richTextFilling] : [])))->toRevision();
                $filled->writeTo($output . '/' . $stem . '-filled.pdf');
                $extendedText[] = ['source' => $stem . '.pdf', 'filled' => $stem . '-filled.pdf', 'kind' => $kind, 'permission' => $permission->value, 'format' => $format->name, 'version' => $version];
                if ($kind === 'richtext') {
                    $refuse($stem . ':missing-xml-interpreter', new IncrementalUpdate($revision, new FileBody(...$objects)));
                }
            }
        }
    }
}
$appearance = static fn (string $color): StreamObject => new StreamObject('q ' . $color . ' rg 0 0 20 20 re f Q', new DictionaryType(
    new Entry(new NameType('Type'), new NameType('XObject')),
    new Entry(new NameType('Subtype'), new NameType('Form')),
    new Entry(new NameType('BBox'), new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(20), new IntegerType(20))),
    new Entry(new NameType('Resources'), new DictionaryType()),
));
$annotation = static fn (StreamObject $appearance): DictionaryType => new DictionaryType(
    new Entry(new NameType('Type'), new NameType('Annot')),
    new Entry(new NameType('Subtype'), new NameType('Square')),
    new Entry(new NameType('Rect'), new Rectangle(new IntegerType(20), new IntegerType(20), new IntegerType(40), new IntegerType(40))),
    new Entry(new NameType('Contents'), new TextString('review')),
    new Entry(new NameType('F'), new IntegerType(4)),
    new Entry(new NameType('AP'), new DictionaryType(new Entry(new NameType('N'), new IndirectReference($appearance)))),
);
$addAnnotation = static function (ProtectedRevisionFixture $fixture, DictionaryType $annotation): IncrementalUpdate {
    $page = $fixture->revision->binding($fixture->page);
    return new IncrementalUpdate($fixture->revision, new FileBody(new IndirectObject($page->dictionary->with(
        new Entry(new NameType('Annots'), new ArrayType(new IndirectReference($annotation))),
    ), $page->identifier)));
};
foreach ([DocMdpPermissions::NoChanges, DocMdpPermissions::FormFillingTemplatesAndSigning] as $permission) {
    $fixture = new ProtectedRevisionFixture($permission);
    $refuse('P' . $permission->value . ':annotation', $addAnnotation($fixture, $annotation($appearance('0 0 1'))));
}
foreach ([CrossReferenceFormat::Table, CrossReferenceFormat::Stream] as $format) {
    foreach (['1.7', '2.0'] as $version) {
        $fixture = new ProtectedRevisionFixture(DocMdpPermissions::FormsTemplatesSigningAndAnnotations, format: $format, version: $version);
        $stem = 'annotation-p3-' . strtolower($format->name) . '-' . str_replace('.', '', $version);
        $fixture->revision->writeTo($output . '/' . $stem . '.pdf');
        $blue = $appearance('0 0 1');
        $markup = $annotation($blue);
        $added = $addAnnotation($fixture, $markup)->toRevision();
        $added->writeTo($output . '/' . $stem . '-added.pdf');
        $record = $added->binding($markup);
        $red = $appearance('1 0 0');
        $modified = (new IncrementalUpdate($added, new FileBody(new IndirectObject(
            $record->dictionary
            ->with(new Entry(new NameType('Contents'), new TextString('updated review')))
            ->with(new Entry(new NameType('AP'), new DictionaryType(new Entry(new NameType('N'), new IndirectReference($red))))),
            $record->identifier
        ))))->toRevision();
        $modified->writeTo($output . '/' . $stem . '-modified.pdf');
        $page = $modified->binding($fixture->page);
        $detached = (new IncrementalUpdate($modified, new FileBody(new IndirectObject($page->dictionary->without('Annots'), $page->identifier))))->toRevision();
        $detached->writeTo($output . '/' . $stem . '-detached.pdf');
        if (!str_starts_with($added->bytes, $fixture->revision->bytes) || !str_starts_with($modified->bytes, $added->bytes) || !str_starts_with($detached->bytes, $modified->bytes)) {
            throw new RuntimeException('Every annotation operation must preserve its complete input prefix.');
        }
        $oldAppearance = $added->binding($blue);
        $refuse($stem . ':existing-appearance', new IncrementalUpdate($added, new FileBody(
            new IndirectObject($record->dictionary->with(new Entry(new NameType('Contents'), new TextString('changed'))), $record->identifier),
            new IndirectObject(new StreamObject('q 1 0 0 rg 0 0 20 20 re f Q', $oldAppearance->dictionary->without('Length')), $oldAppearance->identifier),
        )));
        $annotations[] = ['stem' => $stem, 'format' => $format->name, 'version' => $version];
    }
}
$opaque = new ProtectedRevisionFixture(opaque: true);
$refuse('opaque-finalize', $opaque->dss());
$original = $locked->revision;
$manual = new FileRevision($original->bytes, $original->header, $original->crossReferenceOffset, $original->size, $original->root, $original->trailerEntries, $original->crossReferenceFormat, $original->freeListHead, ...[...$original->objects(), ...$original->spans(), ...$original->references(), $original->freeList]);
$refuse('manual-history', new IncrementalUpdate($manual, new FileBody()));
$refuse('cloned-history', new IncrementalUpdate(clone $original, new FileBody()));
$usage = new PendingSignature(new SignatureCapacity(8192), SignatureFormat::Pkcs7Detached, entries: new Entry(new NameType('Reference'), new SignatureReferences(new SignatureReference(new UsageRightsTransform(UsageRightsMode::ByteRange)))));
$catalog = new DocumentCatalog(new PageTreeNode(), new Entry(new NameType('Perms'), new DictionaryType(new Entry(new NameType('UR3'), new IndirectReference($usage)))));
$signed = (new SignatureWriter())->prepare(new FileStructure(new FileHeader('2.0'), new FileBody($catalog)), $usage)->sign($locked->backend);
$record = $signed->revision->binding($catalog);
$refuse('UR:hidden-authority', new IncrementalUpdate($signed->revision, new FileBody(new IndirectObject($record->dictionary->without('Perms'), $record->identifier))));
$usageFixture = static function (array $rights, bool $restrict, string $version) use ($backend): array {
    $transform = new UsageRightsTransform(UsageRightsMode::ByteRange, $restrict, null, ...$rights);
    $pending = new PendingSignature(
        new SignatureCapacity(8192),
        SignatureFormat::Pkcs7Detached,
        entries: new Entry(new NameType('Reference'), new SignatureReferences(new SignatureReference($transform)))
    );
    $field = new TextField(new PartialFieldName(new TextString('value')), value: new TextString('original'));
    $font = require dirname(__DIR__, 2) . '/examples/fixtures/pdf20-courier-font.php';
    $resources = new DictionaryType(new Entry(new NameType('Font'), new DictionaryType(new Entry(new NameType('F'), new IndirectReference($font)))));
    $form = new AcroForm((new FieldTree(new DefaultAppearance(new SetTextFontAndSize(new NameType('F'), new IntegerType(12))), $resources))->addRoot($field));
    $content = new StreamObject('q Q');
    $page = new PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new ResourceDictionary(), $content);
    $catalog = new DocumentCatalog(
        (new PageTreeNode())->addKid($page),
        new Entry(new NameType('AcroForm'), new IndirectReference($form)),
        new Entry(new NameType('Perms'), new DictionaryType(new Entry(new NameType('UR3'), new IndirectReference($pending))))
    );
    $revision = (new SignatureWriter())->prepare(new FileStructure(new FileHeader($version), new FileBody($catalog)), $pending)->sign($backend)->revision;
    return [$revision, $field, $catalog, $content];
};
$authorize = static function (FileRevision $revision, bool $permit, UsageRight ...$rights): UsageRightsAuthorization {
    $fingerprint = hash('sha256', $revision->verifiedSignature->facts->certificate->certificate()->value);
    $authority = new class ($fingerprint, $permit) implements UsageRightsAuthority {
        public bool $called = false;
        public function __construct(private string $fingerprint, private bool $permit)
        {
        }
        public function permits(VerifiedRevisionSignature $signature): bool
        {
            $this->called = true;
            return $this->permit && hash_equals($this->fingerprint, hash('sha256', $signature->facts->certificate->certificate()->value));
        }
    };
    return new UsageRightsAuthorization($authority, ...$rights);
};
$usageFill = static function (FileRevision $revision, TextField $field, ?UsageRightsAuthorization $authorization = null): IncrementalUpdate {
    $record = $revision->binding($field);
    return new IncrementalUpdate(
        $revision,
        new FileBody(new IndirectObject($record->dictionary->with(new Entry(new NameType('V'), new TextString('changed'))), $record->identifier)),
        null,
        null,
        ...($authorization === null ? [] : [$authorization])
    );
};
foreach ([
    ['ur3-p17', [UsageRight::FillField], true, '1.7', true],
    ['ur3-p20', [UsageRight::FillField], true, '2.0', true],
    ['ur3-fullsave17', [UsageRight::FillField, UsageRight::FullSave], false, '1.7', true],
    ['ur3-nosave17', [UsageRight::FillField], false, '1.7', false],
    ['ur3-unrelated-save', [UsageRight::FillField, UsageRight::CreateAnnotation], false, '1.7', false],
    ['ur3-fullsave-only', [UsageRight::FullSave], true, '1.7', false],
    ['ur3-signature-only', [UsageRight::ModifySignature], true, '1.7', false],
    ['ur3-false20', [UsageRight::FillField, UsageRight::FullSave], false, '2.0', false],
] as [$stem, $rights, $restrict, $version, $allowed]) {
    [$revision, $field, $catalog, $content] = $usageFixture($rights, $restrict, $version);
    $revision->writeTo($output . '/' . $stem . '.pdf');
    $authorization = $authorize($revision, true, UsageRight::FillField);
    $update = $usageFill($revision, $field, $authorization);
    if ($allowed) {
        $filled = $update->toRevision();
        if (!str_starts_with($filled->bytes, $revision->bytes) || !$authorization->authority->called) {
            throw new RuntimeException('An authorized fill preserves the prefix and actually consults the application authority.');
        }
        $filled->writeTo($output . '/' . $stem . '-filled.pdf');
    } else {
        $refuse('UR:' . $stem, $update);
    }
    $usageRights[] = ['stem' => $stem, 'rights' => array_map(static fn (UsageRight $right): string => $right->value, $rights),
        'restrict' => $restrict, 'version' => $version, 'allowed' => $allowed,
        'signerSha256' => hash('sha256', $revision->verifiedSignature->facts->certificate->certificate()->value),
        'applicationScope' => UsageRight::FillField->value];
    if ($stem === 'ur3-p17') {
        $refuse('UR:no-authority', $usageFill($revision, $field));
        $denied = $authorize($revision, false, UsageRight::FillField);
        $refuse('UR:authority-refused', $usageFill($revision, $field, $denied));
        if (!$denied->authority->called) {
            throw new RuntimeException('The refusing authority must actually receive verified signer facts.');
        }
        $refuse('UR:missing-scope', $usageFill($revision, $field, $authorize($revision, true, UsageRight::ModifyAnnotation)));
        $refuse('UR:authority-not-persistent', $usageFill($filled, $field));
        $contentRecord = $revision->binding($content);
        $refuse('UR:hidden-content', new IncrementalUpdate($revision, new FileBody(...[...$update->body->objects(),
            new IndirectObject(new StreamObject('0 0 10 10 re f'), $contentRecord->identifier)]), null, null, $authorization));
        $catalogRecord = $revision->binding($catalog);
        $refuse('UR:removed-permissions', new IncrementalUpdate($revision, new FileBody(...[...$update->body->objects(),
            new IndirectObject($catalogRecord->dictionary->without('Perms'), $catalogRecord->identifier)]), null, null, $authorization));
    }
}
echo json_encode(['rejected' => $rejected, 'form_fills' => $forms, 'extended_text_fills' => $extendedText, 'annotations' => $annotations, 'usage_rights' => $usageRights], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
