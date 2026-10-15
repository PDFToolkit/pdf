<?php

declare(strict_types=1);

namespace PDF\Document\Structure;

/** Domains are assigned by standard relationships, never inferred from arbitrary private key names. */
enum WtPdfDeprecatedDomain
{
    case Catalog;
    case Pages;
    case Resources;
    case XObject;
    case Pattern;
    case Font;
    case Type1Descriptor;
    case CidDescriptor;
    case ExtGState;
    case Content;
    case Annotation;
    case Appearance;
    case Action;
    case CatalogActions;
    case PageActions;
    case AnnotationActions;
    case FieldActions;
    case Outline;
    case NavigationNode;
    case Names;
    case EmbeddedNameTree;
    case ActionNameTree;
    case FileSpec;
    case EmbeddedFile;
    case EmbeddedParams;
    case AcroForm;
    case Field;
    case Signature;
    case SignatureReference;
    case Seed;
    case ViewerPreferences;
    case MarkInfo;
    case Permissions;
    case Legal;
    case StructureRoot;
    case StructureElement;
    case AttributeAssociations;
    case Attribute;
    case ClassMap;
    case Trailer;
    case Info;
    case Encryption;
    case CryptFilter;
    case CidSystemInfo;
    case CMap;
    case TrapAppearance;
}
