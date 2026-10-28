<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Data;

use JardisSupport\Workflow\Data\Answer;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Exception\ConstructionException;
use PHPUnit\Framework\TestCase;

/**
 * answerFrom() on the definition: declared or not, checked at build(), never mixed with the old answer forms.
 */
class AnswerFromDefinitionTest extends TestCase
{
    private const A = 'Node\\A';
    private const B = 'Node\\B';

    private function graph(): ProcessDefinition
    {
        return ProcessDefinition::for('Input\\Dto', 'P');
    }

    public function testNotDeclaredIsNull(): void
    {
        $definition = $this->graph()->node(self::A)->terminal()->build();

        $this->assertNull($definition->getAnswerFrom());
    }

    public function testDeclaredWithoutEntriesIsAnEmptyList(): void
    {
        $definition = $this->graph()->answerFrom()->node(self::A)->terminal()->build();

        $this->assertSame([], $definition->getAnswerFrom());
    }

    public function testEntriesAreKeptInOrder(): void
    {
        $definition = $this->graph()
            ->answerFrom([self::A], [self::B, self::A])
            ->node(self::A)->onTrue(self::B)
            ->node(self::B)->terminal()
            ->build();

        $this->assertSame([[self::A], [self::B, self::A]], $definition->getAnswerFrom());
    }

    public function testNodesMayBeDeclaredAfterTheAnswer(): void
    {
        $definition = $this->graph()->node(self::A)->terminal()->answerFrom([self::A])->build();

        $this->assertSame([[self::A]], $definition->getAnswerFrom());
    }

    public function testAnEmptyEntryIsRefused(): void
    {
        $definition = $this->graph()->answerFrom([self::A], [])->node(self::A)->terminal();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('entry 2 names no node');

        $definition->build();
    }

    public function testAnUnknownNodeIsRefused(): void
    {
        $definition = $this->graph()->answerFrom([self::A, 'Node\\Missing'])->node(self::A)->terminal();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('unknown node "Node\\Missing"');

        $definition->build();
    }

    public function testMixedWithMergedIsRefused(): void
    {
        $definition = $this->graph()->answer(Answer::Merged)->answerFrom([self::A])->node(self::A)->terminal();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('cannot be mixed');

        $definition->build();
    }

    public function testMixedWithAnswerResponseIsRefused(): void
    {
        $definition = $this->graph()->answerResponse('Some\\Answer')->answerFrom([self::A])->node(self::A)->terminal();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('cannot be mixed');

        $definition->build();
    }

    public function testMixedWithANodeResponseIsRefused(): void
    {
        $definition = $this->graph()->answerFrom([self::A])->node(self::A)->response('Some\\Response')->terminal();

        $this->expectException(ConstructionException::class);
        $this->expectExceptionMessage('cannot be mixed');

        $definition->build();
    }

    public function testTheDefaultLastNodeFormDoesNotBlockIt(): void
    {
        $definition = $this->graph()->answer(Answer::LastNode)->answerFrom([self::A])->node(self::A)->terminal()->build();

        $this->assertSame([[self::A]], $definition->getAnswerFrom());
    }

    public function testAfterBuildItCannotBeChanged(): void
    {
        $definition = $this->graph()->node(self::A)->terminal()->build();

        $this->expectException(ConstructionException::class);

        $definition->answerFrom([self::A]);
    }
}
