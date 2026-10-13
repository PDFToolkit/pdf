<?php

declare(strict_types=1);

namespace PDF\Tests;

use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\OptionalContent\BaseState;
use PDF\Graphics\OptionalContent\Configuration;
use PDF\Graphics\OptionalContent\EventUsage;
use PDF\Graphics\OptionalContent\GroupCollection;
use PDF\Graphics\OptionalContent\GroupOrder;
use PDF\Graphics\OptionalContent\GroupStates;
use PDF\Graphics\OptionalContent\GroupUsage;
use PDF\Graphics\OptionalContent\Intent;
use PDF\Graphics\OptionalContent\Intents;
use PDF\Graphics\OptionalContent\LanguageUsage;
use PDF\Graphics\OptionalContent\OptionalContentAssociation;
use PDF\Graphics\OptionalContent\OptionalContentGroup;
use PDF\Graphics\OptionalContent\OptionalContentMembership;
use PDF\Graphics\OptionalContent\OptionalContentProperties;
use PDF\Graphics\OptionalContent\OrderBranch;
use PDF\Graphics\OptionalContent\RadioButtonGroups;
use PDF\Graphics\OptionalContent\UsageApplication;
use PDF\Graphics\OptionalContent\UsageCategory;
use PDF\Graphics\OptionalContent\UsageEnvironment;
use PDF\Graphics\OptionalContent\UsageEvaluator;
use PDF\Graphics\OptionalContent\UsageEvent;
use PDF\Graphics\OptionalContent\UserType;
use PDF\Graphics\OptionalContent\UserUsage;
use PDF\Graphics\OptionalContent\VisibilityExpression;
use PDF\Graphics\OptionalContent\VisibilityOperator;
use PDF\Graphics\OptionalContent\VisibilityPolicy;
use PDF\Graphics\OptionalContent\ZoomUsage;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\EndMarkedContent;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DataStructure\TextString;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\BooleanType;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionalContentTest extends TestCase
{
    public function testGroupNamesDoNotCreateGlobalOrDocumentIdentityCollisions(): void
    {
        $first = $this->group('Equal');
        $second = $this->group('Equal');
        $groups = new GroupCollection($first, $second);
        self::assertCount(2, $groups);
        self::assertNotSame($first, $second);
        $one = new GroupStates($groups);
        $two = new GroupStates($groups);
        $one->set($first, false);
        self::assertFalse($one->state($first));
        self::assertTrue($two->state($first));
    }

    #[DataProvider('visibilityPolicies')]
    public function testAllFourPoliciesAndEmptyMembershipHaveSourceSemantics(VisibilityPolicy $policy, bool $expected): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $groups = new GroupCollection($a, $b);
        $states = new GroupStates($groups);
        $states->set($b, false);
        self::assertSame($expected, (new OptionalContentMembership($groups, $policy))->visible($states));
        self::assertTrue((new OptionalContentMembership(new GroupCollection(), $policy))->visible($states));
    }

    public static function visibilityPolicies(): array
    {
        return [[VisibilityPolicy::AllOn, false], [VisibilityPolicy::AnyOn, true], [VisibilityPolicy::AnyOff, true], [VisibilityPolicy::AllOff, false]];
    }

    public function testRecursiveExpressionsPreferVeAndKeepTheirCanonicalGroupReferences(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $states = new GroupStates(new GroupCollection($a, $b));
        $states->set($b, false);
        $expression = new VisibilityExpression(VisibilityOperator::And, $a, new VisibilityExpression(VisibilityOperator::Not, $b));
        $membership = new OptionalContentMembership(new GroupCollection($a), VisibilityPolicy::AnyOff, $expression);
        self::assertTrue($membership->visible($states));
        self::assertCount(2, $membership->groups());
        self::assertSame($a, $expression->items[1]->target());
        self::assertStringContainsString('[/And ', $expression->toPdf(new SerializationContext()));
    }

    public function testUnmatchedAndEmptyIntentsAddNoVisibilityConstraint(): void
    {
        $a = $this->group('A');
        $design = new OptionalContentGroup(new TextString('Design'), new Intents(Intent::Design));
        $states = new GroupStates(new GroupCollection($a, $design));
        $states->set($design, false);
        self::assertTrue($design->visible($states));
        self::assertTrue((new OptionalContentMembership(expression: new VisibilityExpression(VisibilityOperator::Not, $design)))->visible($states));
        $states->set($a, false);
        self::assertFalse((new OptionalContentMembership(expression: new VisibilityExpression(VisibilityOperator::And, $a, $design)))->visible($states));
        $states->apply(new Configuration(BaseState::Unchanged, intents: new Intents()));
        self::assertTrue((new OptionalContentMembership(expression: new VisibilityExpression(VisibilityOperator::Not, $a)))->visible($states));
    }

    public function testDefaultConfigurationRequiresOnAndViewExactly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalContentProperties(new GroupCollection(), new Configuration(BaseState::Off));
    }

    public function testDefaultConfigurationCannotSelectAllIntents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalContentProperties(new GroupCollection(), new Configuration(intents: new Intents(Intent::All)));
    }

    public function testOcgCannotDeclareConfigurationOnlyAllIntent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionalContentGroup(new TextString('Wrong'), new Intents(Intent::All));
    }

    public function testConfigurationBaseStatesApplyBeforeExplicitOverrides(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $states = new GroupStates(new GroupCollection($a, $b));
        $states->apply(new Configuration(BaseState::Off, new GroupCollection($a)));
        self::assertTrue($states->state($a));
        self::assertFalse($states->state($b));
        $states->apply(new Configuration(BaseState::Unchanged, on: new GroupCollection($b)));
        self::assertTrue($states->state($a));
        self::assertTrue($states->state($b));
    }

    public function testRadioButtonsAllowAllOffAndLockChangesAreAtomic(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $groups = new GroupCollection($a, $b);
        $default = new Configuration(off: new GroupCollection($b), radioGroups: new RadioButtonGroups($groups), locked: new GroupCollection($a));
        $states = new GroupStates($groups, $default);
        try {
            $states->setFromUser($b, true);
            self::fail('Locked ON radio member cannot be disabled by a user change.');
        } catch (\LogicException) {
            self::assertTrue($states->state($a));
            self::assertFalse($states->state($b));
        }
        $states->set($a, false); // Programmatic changes remain allowed for Locked.
        $states->setFromUser($b, true);
        $states->setFromUser($b, false);
        self::assertFalse($states->state($a));
        self::assertFalse($states->state($b));
    }

    public function testAlternativeConfigurationInheritsTheDefaultRadioCollections(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $groups = new GroupCollection($a, $b);
        $states = new GroupStates($groups, new Configuration(radioGroups: new RadioButtonGroups($groups)));
        $states->apply(new Configuration(BaseState::Unchanged));
        $states->setFromUser($b, true);
        self::assertFalse($states->state($a));
        self::assertTrue($states->state($b));
    }

    public function testUsageZoomBoundsAreHalfOpenAndMissingUsageLeavesStateAlone(): void
    {
        $low = new OptionalContentGroup(new TextString('Low'), usage: new GroupUsage(new ZoomUsage(maximum: new NumericType(1))));
        $high = new OptionalContentGroup(new TextString('High'), usage: new GroupUsage(new ZoomUsage(new NumericType(1), new NumericType(2))));
        $untouched = $this->group('Unspecified');
        $groups = new GroupCollection($low, $high, $untouched);
        $states = new GroupStates($groups);
        $states->set($untouched, false);
        $configuration = new Configuration(applications: [new UsageApplication(UsageEvent::View, $groups, UsageCategory::Zoom)]);
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(new NumericType(1)));
        self::assertFalse($states->state($low));
        self::assertTrue($states->state($high));
        self::assertFalse($states->state($untouched));
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(new NumericType(2)));
        self::assertFalse($states->state($high));
    }

    public function testRecommendationsAcrossMultipleApplicationsConjoinAndIgnoreOtherEvents(): void
    {
        $group = new OptionalContentGroup(new TextString('Combined'), usage: new GroupUsage(new ZoomUsage(), new EventUsage(UsageEvent::View, new BooleanType(false)), new EventUsage(UsageEvent::Print, new BooleanType(true))));
        $groups = new GroupCollection($group);
        $configuration = new Configuration(applications: [new UsageApplication(UsageEvent::View, $groups, UsageCategory::Zoom), new UsageApplication(UsageEvent::View, $groups, UsageCategory::View), new UsageApplication(UsageEvent::Print, $groups, UsageCategory::Print)]);
        $states = new GroupStates($groups);
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment());
        self::assertFalse($states->state($group));
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::Print, new UsageEnvironment());
        self::assertTrue($states->state($group));
    }

    public function testLanguageExactMatchesWinBeforePreferredPartialMatches(): void
    {
        $us = new OptionalContentGroup(new TextString('US'), usage: new GroupUsage(new LanguageUsage(new TextString('en-US'))));
        $gb = new OptionalContentGroup(new TextString('GB'), usage: new GroupUsage(new LanguageUsage(new TextString('en-GB'), new BooleanType(true))));
        $groups = new GroupCollection($us, $gb);
        $configuration = new Configuration(applications: [new UsageApplication(UsageEvent::View, $groups, UsageCategory::Language)]);
        $states = new GroupStates($groups);
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(language: new TextString('EN-us')));
        self::assertTrue($states->state($us));
        self::assertFalse($states->state($gb));
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(language: new TextString('en-AU')));
        self::assertFalse($states->state($us));
        self::assertTrue($states->state($gb));
    }

    public function testUserUsageMatchesTheDeclaredIdentificationCategoryExactly(): void
    {
        $group = new OptionalContentGroup(new TextString('Org'), usage: new GroupUsage(new UserUsage(UserType::Organization, new TextString('PDFToolkit'))));
        $groups = new GroupCollection($group);
        $states = new GroupStates($groups);
        $configuration = new Configuration(applications: [new UsageApplication(UsageEvent::View, $groups, UsageCategory::User)]);
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(users: ['Org' => [new TextString('PDFToolkit')]]));
        self::assertTrue($states->state($group));
        (new UsageEvaluator())->apply($states, $configuration, UsageEvent::View, new UsageEnvironment(users: ['Ind' => [new TextString('PDFToolkit')]]));
        self::assertFalse($states->state($group));
    }

    public function testOrderLabelsAreDirectAndConfigurationReferencesStayWithinTheDocument(): void
    {
        $group = $this->group('Visible');
        $order = new GroupOrder(new OrderBranch(new TextString('Collection'), $group));
        self::assertSame('(Collection)', $order->items[0]->items[0]->toPdf(new SerializationContext()));
        self::assertSame($group, $order->items[0]->items[1]->target());
        $this->expectException(\InvalidArgumentException::class);
        new OptionalContentProperties(new GroupCollection(), new Configuration(order: $order));
    }

    public function testWriterRejectsAnAttachedOcgWithoutOcProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->file($this->group('Missing root'))->toPdf();
    }

    public function testOptionalContentRequiresPdf15InTheActualWriter(): void
    {
        $group = $this->group('Layer');
        $this->expectException(\LogicException::class);
        $this->file($group, new OptionalContentProperties(new GroupCollection($group)), false, '1.4')->toPdf();
    }

    public function testVisibilityExpressionsRequirePdf16InTheActualWriter(): void
    {
        $group = $this->group('Layer');
        $membership = new OptionalContentMembership(expression: new VisibilityExpression(VisibilityOperator::Not, $group));
        $this->expectException(\LogicException::class);
        $this->file($membership, new OptionalContentProperties(new GroupCollection($group)), false, '1.5')->toPdf();
    }

    public function testWriterRejectsAnUnregisteredGroupReachedOnlyThroughVe(): void
    {
        $a = $this->group('Registered');
        $b = $this->group('Unregistered');
        $membership = new OptionalContentMembership(expression: new VisibilityExpression(VisibilityOperator::Not, $b));
        $this->expectException(\InvalidArgumentException::class);
        $this->file($membership, new OptionalContentProperties(new GroupCollection($a)))->toPdf();
    }

    public function testWriterRejectsADirectGroupDictionaryInAPropertiesResource(): void
    {
        $group = $this->group('Direct');
        $this->expectException(\InvalidArgumentException::class);
        $this->file($group, new OptionalContentProperties(new GroupCollection($group)), true)->toPdf();
    }

    public function testWriterAcceptsCanonicalGroupsAndNativeMembershipProperties(): void
    {
        $a = $this->group('A');
        $b = $this->group('B');
        $properties = new OptionalContentProperties(new GroupCollection($a, $b), new Configuration(off: new GroupCollection($b)));
        $pdf = $this->file(new OptionalContentMembership(new GroupCollection($a, $b), VisibilityPolicy::AllOn), $properties)->toPdf();
        self::assertStringContainsString('/OCProperties ', $pdf);
        self::assertStringContainsString('/Type /OCG', $pdf);
        self::assertStringContainsString('/Type /OCMD', $pdf);
        self::assertTrue($properties->states()->state($a));
        self::assertFalse($properties->states()->state($b));
    }

    public function testVisibilityNotCannotHaveMultipleOperands(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VisibilityExpression(VisibilityOperator::Not, $this->group('A'), $this->group('B'));
    }

    private function group(string $name): OptionalContentGroup
    {
        return new OptionalContentGroup(new TextString($name));
    }

    private function file(\PDF\Graphics\OptionalContent\OptionalContentObject $membership, ?OptionalContentProperties $properties = null, bool $direct = false, string $version = '1.7'): FileStructure
    {
        $association = new OptionalContentAssociation(new NameType('Layer'), $membership);
        $resource = $direct ? new Entry(new NameType('Layer'), $membership) : $association->propertyEntry();
        $resources = new ResourceDictionary(new Entry(new NameType('Properties'), new DictionaryType($resource)));
        $box = new Rectangle(new NumericType(0), new NumericType(0), new NumericType(100), new NumericType(100));
        $page = new PageObject($box, $resources, new StreamObject(new ContentStreams($association->begin(), new EndMarkedContent())));
        $pages = (new PageTreeNode())->addKid($page);
        $catalog = new DocumentCatalog($pages, ...($properties === null ? [] : [$properties->catalogEntry()]));
        return new FileStructure(new FileHeader($version), new FileBody($catalog), new IndirectReference($catalog));
    }
}
