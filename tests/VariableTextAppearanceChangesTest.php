<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\Signatures\VariableTextAppearanceChanges;
use PDF\Syntax\FileStructure\RevisionStreamSnapshot;
use PDF\Syntax\Object\ArrayType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IntegerType;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NullType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableTextAppearanceChangesTest extends TestCase
{
    private const string OLD = '/Tx BMC q BT /F1 12 Tf (old) Tj ET Q EMC';
    private const string NEW = '/Tx BMC q BT /F1 12 Tf (new) Tj ET Q EMC';

    private function plain(string $bytes): RevisionStreamSnapshot
    {
        return new RevisionStreamSnapshot(new DictionaryType(), $bytes);
    }

    private function flate(string $bytes, int $level = 6, bool $array = false): RevisionStreamSnapshot
    {
        $encoded = gzcompress($bytes, $level);
        self::assertIsString($encoded);
        return new RevisionStreamSnapshot(new DictionaryType(
            new Entry(new NameType('Filter'), $array ? new ArrayType(new NameType('FlateDecode')) : new NameType('FlateDecode')),
            new Entry(new NameType('DecodeParms'), $array ? new ArrayType(new NullType()) : new NullType()),
        ), $encoded);
    }

    /** @return iterable<string, array{string, string}> */
    public static function permittedChanges(): iterable
    {
        yield 'replace only the variable text region' => ["q 1 0 0 rg\n" . self::OLD . "\nQ", "q 1 0 0 rg\n" . self::NEW . "\nQ"];
        yield 'append when no text marker exists' => ['q Q', 'q Q ' . self::NEW];
        yield 'append after a trailing comment' => ["q Q % /Tx BMC EMC", "q Q % /Tx BMC EMC\n" . self::NEW . "\n% end"];
        yield 'unchanged stream without text marker' => ['q Q', 'q Q'];
        yield 'escaped tag name is the same PDF name' => [str_replace('/Tx', '/T#78', self::OLD), self::NEW];
        yield 'surrounding marked content remains intact' => ["/Artifact BMC\n" . self::OLD . "\nEMC", "/Artifact BMC\n" . self::NEW . "\nEMC"];
        yield 'comments cannot close or open marked content' => [self::OLD, "/Tx BMC % EMC /Tx BMC\nq BT (new) Tj ET Q EMC"];
        yield 'literal strings and hexadecimal strings cannot be markers' => [self::OLD, '/Tx BMC q BT [(EMC /Tx BMC) <454D43> 12 (a\\(b\\)c)] TJ ET Q EMC'];
        yield 'balanced nested marked content has its own end' => [self::OLD, '/Tx BMC q /Span << /ActualText (EMC /Tx BMC) /Extra [true false null << /N <a> >>] >> BDC BT (new) Tj ET EMC Q EMC'];
        yield 'literal nesting and escaped newline are data' => [self::OLD, "/Tx BMC q BT (a(b)c\\\r\nEMC) Tj ET Q EMC"];
        yield 'fake marker outside real marker remains immutable' => ["BT (/Tx BMC EMC) Tj ET\n" . self::OLD, "BT (/Tx BMC EMC) Tj ET\n" . self::NEW];
        yield 'owned graphics state isolates changes from suffix painting' => ["/Tx BMC EMC\n0 0 10 10 re f", "/Tx BMC q 10 0 0 10 0 0 cm Q EMC\n0 0 10 10 re f"];
        yield 'a path and clipping can end inside owned graphics state' => [self::OLD, '/Tx BMC q 0 0 10 10 re W n BT (new) Tj ET Q EMC'];
        yield 'nested graphics scopes consume their own paths' => [self::OLD, '/Tx BMC q q 0 0 m 10 10 l S Q BT (new) Tj ET Q EMC'];
        yield 'completed outside path does not enter the section' => ['0 0 10 10 re f ' . self::OLD, '0 0 10 10 re f ' . self::NEW];
    }

    #[DataProvider('permittedChanges')]
    public function testOnlyAnActualBalancedTextSectionCanBeReplacedOrAppended(string $old, string $new): void
    {
        (new VariableTextAppearanceChanges())->validate($this->plain($old), $this->plain($new));
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenChanges(): iterable
    {
        yield 'changed prefix graphics' => ['1 g ' . self::OLD, '0 g ' . self::NEW];
        yield 'changed suffix graphics' => [self::OLD . ' 1 g', self::NEW . ' 0 g'];
        yield 'changed outside comment bytes' => ["% old\n" . self::OLD, "% new\n" . self::NEW];
        yield 'removed existing text section' => [self::OLD, 'q Q'];
        yield 'inserted before rather than after the original' => ['q Q', self::NEW . ' q Q'];
        yield 'extra paint before appended section' => ['q Q', 'q Q 0 g ' . self::NEW];
        yield 'extra paint after appended section' => ['q Q', 'q Q ' . self::NEW . ' 0 g'];
        yield 'fake section in a string' => ['BT (/Tx BMC old EMC) Tj ET', 'BT (/Tx BMC new EMC) Tj ET'];
        yield 'fake section in a comment' => ["% /Tx BMC old EMC\nq Q", "% /Tx BMC new EMC\nq Q"];
        yield 'fake section in a hex string' => ['BT <2f547820424d43206f6c6420454d43> Tj ET', 'BT <2f547820424d43206e657720454d43> Tj ET'];
        yield 'multiple sections are not one replaceable region' => [self::OLD . ' ' . self::OLD, self::NEW . ' ' . self::NEW];
        yield 'nested Tx is ambiguous' => [self::OLD, '/Tx BMC ' . self::NEW . ' EMC'];
        yield 'Tx BDC is not Tx BMC' => [self::OLD, '/Tx <<>> BDC BT (new) Tj ET EMC'];
        yield 'inline image cannot hide a matching EMC' => [self::OLD, '/Tx BMC BI /W 1 /H 1 ID EMC EI EMC'];
        yield 'unmatched marked content' => [self::OLD, '/Tx BMC /Span BMC BT (new) Tj ET EMC'];
        yield 'extra marked content end' => [self::OLD, self::NEW . ' EMC'];
        yield 'unterminated literal' => [self::OLD, '/Tx BMC BT (new Tj ET EMC'];
        yield 'trailing escape in literal' => [self::OLD, '/Tx BMC BT (new\\'];
        yield 'invalid hex digit' => [self::OLD, '/Tx BMC BT <EMC> Tj ET EMC'];
        yield 'unterminated hexadecimal string' => [self::OLD, '/Tx BMC BT <00 Tj ET EMC'];
        yield 'malformed array' => [self::OLD, '/Tx BMC BT [(new) TJ ET EMC'];
        yield 'operators inside a PDF array are not objects' => [self::OLD, '/Tx BMC BT [(new) EMC] TJ ET EMC'];
        yield 'malformed dictionary value' => [self::OLD, '/Tx BMC /Span << /ActualText >> BDC EMC EMC'];
        yield 'duplicate escaped dictionary key' => [self::OLD, '/Tx BMC /Span << /A 1 /#41 2 >> BDC EMC EMC'];
        yield 'unterminated dictionary' => [self::OLD, '/Tx BMC /Span << /A 1 BDC EMC EMC'];
        yield 'invalid escaped name' => [self::OLD, '/T#GG BMC BT (new) Tj ET EMC'];
        yield 'null byte in name escape' => [self::OLD, '/Tx#00 BMC BT (new) Tj ET EMC'];
        yield 'extra BMC operands' => [self::OLD, '/Other /Tx BMC BT (new) Tj ET EMC'];
        yield 'unknown operator is not a trusted extension' => [self::OLD, '/Tx BMC SomethingNew EMC'];
        yield 'Type3 d0 does not belong to a Form appearance' => [self::OLD, '/Tx BMC q 600 0 d0 Q EMC'];
        yield 'Type3 d1 does not belong to a Form appearance' => [self::OLD, '/Tx BMC q 600 0 0 0 600 700 d1 Q EMC'];
        yield 'cannot pop prefix graphics state' => ['q ' . self::OLD . ' Q', 'q /Tx BMC Q q EMC Q'];
        yield 'cannot leave a graphics scope open' => [self::OLD, '/Tx BMC q EMC'];
        yield 'cannot close an enclosing text object' => ['BT /Tx BMC (old) Tj EMC ET', 'BT /Tx BMC ET BT (new) Tj EMC ET'];
        yield 'cannot leak a text object' => [self::OLD, '/Tx BMC BT (new) Tj EMC ET'];
        yield 'cannot close an enclosing compatibility section' => ['BX ' . self::OLD . ' EX', 'BX /Tx BMC EX BX EMC EX'];
        yield 'malformed original does not establish a safe prefix' => ['q', 'q ' . self::NEW . ' Q'];
        yield 'cannot mask the original EOF comment as a new operator' => ['% old', '% old' . self::NEW];
        yield 'unchanged suffix can observe a leaked graphics transform' => ["/Tx BMC EMC\n0 0 10 10 re f", "/Tx BMC 10 0 0 10 0 0 cm EMC\n0 0 10 10 re f"];
        yield 'q Q does not restore the current path' => ["/Tx BMC EMC\nS", "/Tx BMC q 0 0 m 100 100 l Q EMC\nS"];
        yield 'q Q does not restore outside text matrices' => ['BT /Tx BMC EMC (outside) Tj ET', 'BT /Tx BMC q 1 0 0 1 100 100 Tm Q EMC (outside) Tj ET'];
        yield 'outside path cannot be consumed by variable text' => ['0 0 m 10 10 l /Tx BMC EMC S', '0 0 m 10 10 l /Tx BMC q n Q EMC S'];
        yield 'outside pending clipping cannot enter variable text' => ['W /Tx BMC EMC n', 'W /Tx BMC q n Q EMC n'];
        yield 'pending clipping cannot leave graphics isolation' => [self::OLD, '/Tx BMC q W Q q n Q EMC'];
        yield 'a path cannot leave inner graphics isolation' => [self::OLD, '/Tx BMC q q 0 0 m 100 100 l Q S Q EMC'];
        yield 'a text object cannot span graphics restoration' => [self::OLD, '/Tx BMC q BT 7 Tr (new) Tj Q ET EMC'];
        yield 'a text object cannot span nested graphics restoration' => [self::OLD, '/Tx BMC q BT q 7 Tr (new) Tj Q ET Q EMC'];
        yield 'text state before owned q is not isolated' => [self::OLD, '/Tx BMC 7 Tr q BT (new) Tj ET Q EMC'];
        yield 'text state after owned q is not isolated' => [self::OLD, '/Tx BMC q BT (new) Tj ET Q 7 Tr EMC'];
    }

    #[DataProvider('forbiddenChanges')]
    public function testHiddenEditsAndAmbiguousContentAreRejected(string $old, string $new): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges())->validate($this->plain($old), $this->plain($new));
    }

    public function testFrozenFilterDictionaryControlsDecodingRegardlessOfCompressedRepresentation(): void
    {
        $old = $this->flate("q\n" . self::OLD . "\nQ", 0, true);
        $new = $this->flate("q\n" . self::NEW . "\nQ", 9);
        self::assertNotSame($old->encodedBytes, $new->encodedBytes);
        (new VariableTextAppearanceChanges())->validate($old, $new);
        (new VariableTextAppearanceChanges())->validate($new, $this->plain("q\n" . self::NEW . "\nQ"));
    }

    /** @return iterable<string, array{string}> */
    public static function badCompressedStreams(): iterable
    {
        yield 'truncated zlib member' => [substr((string) gzcompress(self::NEW), 0, -2)];
        yield 'trailing bytes' => [(string) gzcompress(self::NEW) . 'garbage'];
        yield 'concatenated zlib members' => [(string) gzcompress(self::NEW) . (string) gzcompress(self::NEW)];
        yield 'not zlib data' => [self::NEW];
        yield 'empty compressed data' => [''];
    }

    #[DataProvider('badCompressedStreams')]
    public function testOnlyOneCompleteFlateMemberIsAccepted(string $encoded): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges())->validate($this->plain(self::OLD), new RevisionStreamSnapshot(new DictionaryType(new Entry(new NameType('Filter'), new NameType('FlateDecode'))), $encoded));
    }

    public function testUnsupportedFilterCannotTreatEncodedBytesAsPlainText(): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges())->validate($this->plain(self::OLD), new RevisionStreamSnapshot(new DictionaryType(new Entry(new NameType('Filter'), new NameType('ASCIIHexDecode'))), self::NEW));
    }

    public function testPredictorParametersRequireASeparateExplicitDecoder(): void
    {
        $stream = $this->flate(self::NEW);
        $dictionary = $stream->dictionary()->with(new Entry(new NameType('DecodeParms'), new DictionaryType(new Entry(new NameType('Predictor'), new IntegerType(12)))));
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges())->validate($this->plain(self::OLD), new RevisionStreamSnapshot($dictionary, $stream->encodedBytes));
    }

    public function testInflatedOutputIsBoundedBeforeParsing(): void
    {
        $stream = $this->flate('%' . str_repeat('x', 10000));
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges(maximumBytes: 128))->validate($this->plain('q Q'), $stream);
    }

    public function testEncodedInputIsBoundedBeforeDecoding(): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges(maximumBytes: 16))->validate($this->plain(self::OLD), $this->plain(self::NEW));
    }

    public function testLexicalTokenBudgetIsIndependentOfByteBudget(): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges(maximumTokens: 5))->validate($this->plain('q Q q Q q Q'), $this->plain('q Q q Q q Q'));
    }

    public function testCompositeNestingIsBoundedEvenForEmptyInnerContainers(): void
    {
        $this->expectException(\LogicException::class);
        (new VariableTextAppearanceChanges(maximumNesting: 2))->validate($this->plain('[[[]]] TJ'), $this->plain('[[[]]] TJ'));
    }
}
