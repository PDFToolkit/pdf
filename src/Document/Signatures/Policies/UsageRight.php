<?php

declare(strict_types=1);

namespace PDF\Document\Signatures\Policies;

/** Every named right in Table255, grouped by its PDF dictionary entry. */
enum UsageRight: string
{
    case FullSave = 'Document.FullSave';
    case CreateAnnotation = 'Annots.Create';
    case DeleteAnnotation = 'Annots.Delete';
    case ModifyAnnotation = 'Annots.Modify';
    case CopyAnnotation = 'Annots.Copy';
    case ImportAnnotation = 'Annots.Import';
    case ExportAnnotation = 'Annots.Export';
    case OnlineAnnotation = 'Annots.Online';
    case AnnotationSummary = 'Annots.SummaryView';
    case AddField = 'Form.Add';
    case DeleteField = 'Form.Delete';
    case FillField = 'Form.FillIn';
    case ImportForm = 'Form.Import';
    case ExportForm = 'Form.Export';
    case SubmitStandalone = 'Form.SubmitStandalone';
    case SpawnTemplate = 'Form.SpawnTemplate';
    case BarcodePlaintext = 'Form.BarcodePlaintext';
    case OnlineForm = 'Form.Online';
    case ModifySignature = 'Signature.Modify';
    case CreateEmbeddedFile = 'EF.Create';
    case DeleteEmbeddedFile = 'EF.Delete';
    case ModifyEmbeddedFile = 'EF.Modify';
    case ImportEmbeddedFile = 'EF.Import';
    public function key(): string
    {
        return explode('.', $this->value)[0];
    }
    public function name(): string
    {
        return explode('.', $this->value)[1];
    }
    public function requiresUr3(): bool
    {
        return in_array($this, [self::OnlineAnnotation, self::AnnotationSummary, self::BarcodePlaintext, self::OnlineForm], true);
    }
    public function modifiesDocument(): bool
    {
        return !in_array($this, [self::FullSave, self::CopyAnnotation, self::ExportAnnotation, self::AnnotationSummary, self::ExportForm, self::SubmitStandalone], true);
    }
    public function implicitlySavesAnnotations(): bool
    {
        return $this->key() === 'Annots' && $this->modifiesDocument();
    }
}
