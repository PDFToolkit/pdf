<?php

declare(strict_types=1);

use PDF\Document\Forms\SignatureFieldLock;
use PDF\Document\Forms\SignatureLockAction;
use PDF\Document\Signatures\PendingSignature;
use PDF\Document\Signatures\PreparedSignature;
use PDF\Document\Signatures\SignatureCapacity;
use PDF\Document\Signatures\SignatureFormat;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Document\Signatures\Policies\SignatureReference;
use PDF\Document\Signatures\Policies\SignatureReferences;
use PDF\Document\Signatures\Policies\UsageRightsMode;
use PDF\Document\Signatures\Policies\UsageRightsTransform;
use PDF\FileStructure;
use PDF\Serialization\SignatureWriter;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
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
use PDF\Tests\Support\ProtectedRevisionFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$output = $argv[1] ?? throw new InvalidArgumentException('Pass an output directory.');
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    throw new RuntimeException('Cannot create fixture directory.');
}
$rejected = [];
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
    }
    if ($permission === DocMdpPermissions::NoChanges) {
        $fixture->dss()->toRevision()->writeTo($output . '/core-p1-dss.pdf');
    }
}
$locked = new ProtectedRevisionFixture(null, new SignatureFieldLock(SignatureLockAction::Include, new ArrayType(new TextString('name'))));
$locked->revision->writeTo($output . '/core-fieldmdp.pdf');
$refuse('FieldMDP:locked-value', $locked->mutation('field-value'));
$refuse('FieldMDP:hidden-form', $locked->mutation('remove-form'));
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
echo json_encode(['rejected' => $rejected], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
