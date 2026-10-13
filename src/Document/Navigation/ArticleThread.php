<?php

declare(strict_types=1);

namespace PDF\Document\Navigation;

use PDF\Document\DocumentInformation;
use PDF\Document\VersionedComponent;
use PDF\Specification\Definition\ThreadDictionaryTable160Definition;
use PDF\Specification\SchemaDictionary;
use PDF\Syntax\FileStructure\EffectivePdfVersion;
use PDF\Syntax\FileStructure\PdfVersion;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;

/** Ordered article builder; derives its first bead and each circular N/V link. */
final class ArticleThread extends SchemaDictionary implements VersionedComponent
{
    /** @var list<ArticleBead> */
    private array $beads = [];

    public function __construct(?DocumentInformation $information = null, private readonly NavigationLimits $limits = new NavigationLimits())
    {
        $entries = [new Entry(new NameType('Type'), new NameType('Thread'))];
        if ($information !== null) {
            $entries[] = new Entry(new NameType('I'), $information);
        }
        parent::__construct(new ThreadDictionaryTable160Definition(), ...$entries);
    }

    public function addBead(ArticleBead $bead): self
    {
        if (count($this->beads) >= $this->limits->maxNodes) {
            throw new \LengthException('The article exceeds its explicit bead construction budget.');
        }
        $bead->attachThread($this);
        $this->beads[] = $bead;
        return $this;
    }

    /** @return list<ArticleBead> */
    public function beads(): array
    {
        return $this->beads;
    }

    public function entries(): iterable
    {
        if ($this->beads === []) {
            throw new \LogicException('An article thread must contain a first bead.');
        }
        yield new Entry(new NameType('F'), new IndirectReference($this->beads[0]));
        yield from parent::entries();
    }

    public function validateCatalog(DictionaryType $catalog): void
    {
        $threads = $catalog->get('Threads');
        if (!$threads instanceof IndirectReference) {
            throw new \InvalidArgumentException('Catalogue Threads must be an indirect array reference.');
        }
        $threads = $threads->target();
        $count = 0;
        if ($threads instanceof ArrayType) {
            foreach ($threads->items as $ref) {
                if ($ref instanceof IndirectReference && $ref->target() === $this) {
                    ++$count;
                }
            }
        }
        if ($count !== 1 || $this->beads === []) {
            throw new \InvalidArgumentException('An article thread must be registered once in catalogue Threads and have a first bead.');
        }
    }

    public function validateVersion(EffectivePdfVersion $version): void
    {
        $version->require(PdfVersion::PDF11, 'Article threads');
    }

    public function with(Entry $entry): static
    {
        throw new \LogicException('An article is configured by its information and explicit bead builder.');
    }

    public function without(NameType|string $key): static
    {
        throw new \LogicException('An article is configured by its information and explicit bead builder.');
    }

    private function __clone(): void
    {
    }
}
