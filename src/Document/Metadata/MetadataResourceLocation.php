<?php

declare(strict_types=1);

namespace PDF\Document\Metadata;

use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\NameType;

/** PDF20_AN003-ObjectMetadataLocations.pdf pp.4–10: location names identify the resource, not a parent wrapper. */
enum MetadataResourceLocation: string
{
    case Document = 'document';
    case Page = 'page';
    case IccProfile = 'icc-profile-stream';
    case Image = 'image-xobject-stream';
    case Form = 'form-xobject-stream';
    case Thread = 'thread';
    case DocumentPart = 'document-part';
    case EmbeddedFont = 'font-program-stream';
    case TilingPattern = 'tiling-pattern-stream';
    case Shading = 'shading-dictionary';
    case MarkedContent = 'named-property-list';
    case StructureElement = 'structure-element';
    case FileSpecification = 'file-specification';
    case EmbeddedFile = 'embedded-file-stream';
    case Type3Font = 'type3-font';
    case Annotation = 'annotation';
    case OptionalContentGroup = 'optional-content-group';
    case ThreeDArtwork = '3d-artwork-stream';

    public function sourcePage(): int
    {
        return match ($this) {
            self::Document, self::Page, self::IccProfile, self::Image => 4,
            self::Form, self::Thread, self::DocumentPart => 5,
            self::EmbeddedFont => 6,
            self::TilingPattern, self::Shading => 7,
            self::MarkedContent => 8,
            self::StructureElement, self::FileSpecification, self::EmbeddedFile, self::Type3Font => 9,
            self::Annotation, self::OptionalContentGroup, self::ThreeDArtwork => 10,
        };
    }

    /** Attach to a resource dictionary before passing it to the owning stream/object constructor. */
    public function attach(DictionaryType $resource, MetadataStream $metadata): DictionaryType
    {
        $type = $resource->get('Type');
        $subtype = $resource->get('Subtype');
        $requiredType = match ($this) {
            self::Document => 'Catalog', self::Page => 'Page', self::Image, self::Form => 'XObject', self::Thread => 'Thread', self::DocumentPart => 'DPart', self::StructureElement => 'StructElem', self::FileSpecification => 'Filespec', self::EmbeddedFile => 'EmbeddedFile', self::Type3Font => 'Font', self::Annotation => 'Annot', self::OptionalContentGroup => 'OCG', self::ThreeDArtwork => '3D', default => null,
        };
        if ($requiredType !== null && (!$type instanceof NameType || $type->value !== $requiredType)) {
            throw new \InvalidArgumentException('The selected metadata location does not describe this resource dictionary.');
        }
        $requiredSubtype = match ($this) {
            self::Image => 'Image', self::Form => 'Form', self::Type3Font => 'Type3', default => null
        };
        if ($requiredSubtype !== null && (!$subtype instanceof NameType || $subtype->value !== $requiredSubtype)) {
            throw new \InvalidArgumentException('The selected metadata location requires its native resource subtype.');
        }
        if ($this === self::Shading && !$resource->has('ShadingType')) {
            throw new \InvalidArgumentException('Shading metadata belongs on the shading dictionary, not the pattern wrapper.');
        }
        if ($this === self::EmbeddedFont && ($resource->has('FontDescriptor') || $resource->has('FontName') || $resource->has('BaseFont'))) {
            throw new \InvalidArgumentException('Font-program metadata belongs on the embedded font stream.');
        }
        return $resource->with(new MetadataEntry($metadata));
    }
}
