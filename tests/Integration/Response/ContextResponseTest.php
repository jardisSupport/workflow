<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Workflow\Data\Response\ContextResponse;
use PHPUnit\Framework\TestCase;

/**
 * ContextResponse: the mutable, context-keyed accumulator.
 */
class ContextResponseTest extends TestCase
{
    public function testImplementsTheKernelContract(): void
    {
        $this->assertInstanceOf(ContextResponseInterface::class, new ContextResponse('order'));
    }

    public function testStartsEmptyAndKeysEverythingByContext(): void
    {
        $response = new ContextResponse('order');

        $this->assertSame(['order' => []], $response->getData());
        $this->assertSame(['order' => []], $response->getEvents());
        $this->assertSame(['order' => []], $response->getErrors());
        $this->assertSame([], $response->getResults());
    }

    public function testAddDataAndSetDataReplace(): void
    {
        $response = (new ContextResponse('order'))->addData('a', 1)->addData('b', 2);
        $this->assertSame(['order' => ['a' => 1, 'b' => 2]], $response->getData());

        $response->setData(['c' => 3]);
        $this->assertSame(['order' => ['c' => 3]], $response->getData());
    }

    public function testErrorsAccumulateInOrder(): void
    {
        $response = (new ContextResponse('order'))->addError('first')->addError('second');

        $this->assertSame(['order' => ['first', 'second']], $response->getErrors());
    }

    public function testEventsAreClassifiedByScopeWithInternalAsDefault(): void
    {
        $internalDefault = new \stdClass();
        $internalExplicit = new \stdClass();
        $domain = new \stdClass();

        $response = (new ContextResponse('order'))
            ->addEvent($internalDefault)
            ->addEvent($domain, EventScope::Domain)
            ->addEvent($internalExplicit, EventScope::Internal);

        $this->assertSame(['order' => [$internalDefault, $internalExplicit]], $response->getEvents(EventScope::Internal));
        $this->assertSame(['order' => [$domain]], $response->getEvents(EventScope::Domain));
    }

    public function testEventsWithoutScopeAreFlattenedPerScopeBucketInInsertionOrder(): void
    {
        $domain = new \stdClass();
        $internalA = new \stdClass();
        $internalB = new \stdClass();

        $response = (new ContextResponse('order'))
            ->addEvent($internalA)
            ->addEvent($domain, EventScope::Domain)
            ->addEvent($internalB);

        // buckets in order of first use: internal (A, B), then domain
        $this->assertSame(['order' => [$internalA, $internalB, $domain]], $response->getEvents());
    }

    public function testSubResultsAreKeptAsGivenAndAreNotMergedIntoOwnGetters(): void
    {
        $child = (new ContextResponse('inventory'))->addData('x', 1);
        $root = (new ContextResponse('order'))->addResult($child);

        $this->assertSame([$child], $root->getResults());
        $this->assertSame(['order' => []], $root->getData());
    }
}
