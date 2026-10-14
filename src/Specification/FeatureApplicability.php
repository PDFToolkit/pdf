<?php

declare(strict_types=1);

namespace PDF\Specification;

use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;

/** An explicit feature's applicability to the effective version of one file. */
final readonly class FeatureApplicability
{
    public function __construct(
        public PdfVersion $introducedIn,
        public ?PdfVersion $deprecatedIn = null,
        public ?PdfVersion $prohibitedIn = null,
    ) {
        foreach ([$deprecatedIn, $prohibitedIn] as $change) {
            if ($change !== null && version_compare($change->value, $introducedIn->value, '<')) {
                throw new \InvalidArgumentException('A feature cannot be deprecated or prohibited before introduction.');
            }
        }
    }

    public function status(PdfVersion $version): FeatureStatus
    {
        if (version_compare($version->value, $this->introducedIn->value, '<')) {
            return FeatureStatus::NotIntroduced;
        }
        if ($this->prohibitedIn !== null && version_compare($version->value, $this->prohibitedIn->value, '>=')) {
            return FeatureStatus::Prohibited;
        }
        if ($this->deprecatedIn !== null && version_compare($version->value, $this->deprecatedIn->value, '>=')) {
            return FeatureStatus::Deprecated;
        }
        return FeatureStatus::Available;
    }

    public function validate(EffectivePdfVersion $version, string $feature): void
    {
        $version->require($this->introducedIn, $feature);
        if ($this->status($version->value) === FeatureStatus::Prohibited) {
            throw new \LogicException($feature . ' is prohibited in PDF ' . $version->value->value . '.');
        }
    }
}
