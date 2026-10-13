<?php

declare(strict_types=1);

namespace PDF\Graphics\GraphicsState;

use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\ColourSpaceFamily;
use PDF\Graphics\ColourSpaces\ICCBased;

/** PDF 11.3.4 contract; ICC equivalence/bidirectional transforms are supplied explicitly. */
final readonly class BlendingColourSpace
{
    public function __construct(public ColourSpace $space, public ?ICCBlendModel $iccModel = null)
    {
        if (!in_array($space->family(), [ColourSpaceFamily::DeviceGray, ColourSpaceFamily::DeviceRGB, ColourSpaceFamily::DeviceCMYK, ColourSpaceFamily::CalGray, ColourSpaceFamily::CalRGB, ColourSpaceFamily::ICCBased], true)) {
            throw new \InvalidArgumentException('Blending spaces exclude Lab and the special colour spaces.');
        }
        if (($space instanceof ICCBased && ($iccModel === null || $iccModel->value !== $space->componentCount())) || (!$space instanceof ICCBased && $iccModel !== null)) {
            throw new \InvalidArgumentException('ICC blending needs an explicit matching bidirectional Gray/RGB/CMYK equivalence contract.');
        }
        $ranges = $space->ranges();
        if ($ranges === null) {
            throw new \InvalidArgumentException('Blending spaces must declare their independent unit component ranges.');
        }
        foreach ($ranges->intervals as $interval) {
            if ($interval->minimum->value != 0 || $interval->maximum->value != 1) {
                throw new \InvalidArgumentException('Blending components must use independent unit ranges.');
            }
        }
    }

    public function subtractive(): bool
    {
        return $this->space->family() === ColourSpaceFamily::DeviceCMYK || $this->iccModel === ICCBlendModel::CMYK;
    }
}
