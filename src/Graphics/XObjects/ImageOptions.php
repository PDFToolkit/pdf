<?php

declare(strict_types=1);

namespace PDF\Graphics\XObjects;

use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Graphics\OptionalContent\OptionalContentObject;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;

/** Optional image declarations; structural sample/mask entries remain coupled. */
final readonly class ImageOptions
{
    /** @var list<Entry> */
    public array $extensions;

    /** @param list<Entry> $extensions */
    public function __construct(public BooleanType $interpolate = new BooleanType(false), public ?RenderingIntent $intent = null, public ?AlternateImages $alternates = null, public ?OptionalContentObject $optionalContent = null, public ?IntegerType $structParent = null, public ?StreamObject $metadata = null, array $extensions = [])
    {
        if ($structParent !== null && $structParent->integer() < 0) {
            throw new \InvalidArgumentException('Image StructParent must be nonnegative.');
        }
        foreach ($extensions as $entry) {
            if (!$entry instanceof Entry) {
                throw new \InvalidArgumentException('Image extension values must be dictionary entries.');
            }
            if (in_array($entry->key->value, ['Type', 'Subtype', 'Width', 'Height', 'ColorSpace', 'BitsPerComponent', 'Decode', 'Mask', 'SMask', 'ImageMask', 'SMaskInData', 'Matte'], true)) {
                throw new \InvalidArgumentException('Image structural entries are provided by explicit constructor objects.');
            }
        }
        $this->extensions = array_values($extensions);
    }

    /** @return list<Entry> */
    public function entries(): array
    {
        $entries = $this->extensions;
        if (!in_array('Interpolate', array_map(static fn (Entry $entry): string => $entry->key->value, $this->extensions), true)) {
            $entries[] = new Entry(new NameType('Interpolate'), $this->interpolate);
        }
        foreach (['Intent' => $this->intent === null ? null : new NameType($this->intent->value), 'Alternates' => $this->alternates, 'OC' => $this->optionalContent === null ? null : new IndirectReference($this->optionalContent), 'StructParent' => $this->structParent, 'Metadata' => $this->metadata === null ? null : new IndirectReference($this->metadata)] as $name => $value) {
            if ($value !== null) {
                $entries[] = new Entry(new NameType($name), $value);
            }
        }
        return $entries;
    }
}
