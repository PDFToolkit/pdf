<?php

declare(strict_types=1);

namespace PDF\Tests\Support;

use PDF\Document\Forms as Form;
use PDF\Document\Signatures as Sig;
use PDF\Document\Signatures\Policies\DocMdpPermissions;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IndirectObject;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\FileStructure as File;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators as Op;

/** EC3 #170: actual PDF2 radio without Off appearances, through signed fills. */
final readonly class RadioOffFixture
{
    public File\FileRevision $revision;
    public Form\ButtonField $field;
    /** @var list<Form\WidgetAnnotation> */
    public array $widgets;

    public function __construct(DocMdpPermissions $permission = DocMdpPermissions::FormFillingTemplatesAndSigning, File\CrossReferenceFormat $format = File\CrossReferenceFormat::Table)
    {
        $backend = (new ProtectedRevisionFixture())->backend;
        $author = new Sig\SignatureFieldSigning($backend, new Form\PartialFieldName(new TextString('author')), new Sig\SignatureCapacity(8192), new Sig\SignatureSigningIntent(certification: $permission));
        $this->field = new Form\ButtonField(new Form\PartialFieldName(new TextString('radio')), new Form\ButtonFieldFlags(Form\ButtonFieldFlag::Radio), new NameType('A'), semantics: Form\ButtonFieldSemantics::Pdf20);
        $widgets = [];
        foreach (['A', 'B'] as $index => $state) {
            $box = new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(40), new IntegerType(40));
            $stream = new Form\FormAppearanceStream(new ContentStreams(new Op\SetNonstrokingRGBColor(new IntegerType($index === 0 ? 1 : 0), new IntegerType(0), new IntegerType($index === 1 ? 1 : 0)), new Op\AppendRectangle(new IntegerType(0), new IntegerType(0), new IntegerType(40), new IntegerType(40)), new Op\FillPathNonzero()), $box, new DictionaryType());
            $rectangle = new Rectangle(new IntegerType(20 + $index * 60), new IntegerType(20), new IntegerType(60 + $index * 60), new IntegerType(60));
            $widget = new Form\WidgetAnnotation($rectangle, new Form\WidgetAppearance(new Form\AppearanceStates(new Form\AppearanceState(new NameType($state), $stream))), new NameType($index === 0 ? 'A' : 'Off'));
            $this->field->addWidget($widget);
            $widgets[] = $widget;
        }
        $this->widgets = $widgets;
        $page = new \PDF\Syntax\DocumentStructure\PageTree\PageObject(new Rectangle(new IntegerType(0), new IntegerType(0), new IntegerType(200), new IntegerType(200)), new \PDF\Document\ResourceDictionary(), null, new Entry(new NameType('Annots'), new ArrayType(...array_map(static fn ($widget) => new IndirectReference($widget), $widgets))));
        foreach ($widgets as $widget) {
            $widget->bindToPage($page);
        }
        $form = new Form\AcroForm((new Form\FieldTree())->addRoot($author->field)->addRoot($this->field));
        $catalog = new \PDF\Syntax\DocumentStructure\DocumentCatalog((new \PDF\Syntax\DocumentStructure\PageTree\PageTreeNode())->addKid($page), new Entry(new NameType('AcroForm'), new IndirectReference($form)), new Entry(new NameType('Perms'), $author->certificationPermissions()));
        $author->bindCatalog(new IndirectReference($catalog));
        $prepared = (new \PDF\Serialization\SignatureWriter())->prepare(new \PDF\FileStructure(new File\FileHeader('2.0'), new File\FileBody($catalog), crossReferenceFormat: $format), $author->signature);
        $this->revision = $author->sign($prepared)->revision;
    }

    public function select(string $state, ?File\FileRevision $source = null): File\FileRevision
    {
        $source ??= $this->revision;
        $field = $source->binding($this->field);
        $objects = [new IndirectObject($field->dictionary->with(new Entry(new NameType('V'), new NameType($state))), $field->identifier)];
        foreach ($this->widgets as $index => $widget) {
            $record = $source->binding($widget);
            $on = ['A', 'B'][$index];
            $objects[] = new IndirectObject($record->dictionary->with(new Entry(new NameType('AS'), new NameType($state === $on ? $on : 'Off'))), $record->identifier);
        }
        return (new File\IncrementalUpdate($source, new File\FileBody(...$objects)))->toRevision();
    }
}
