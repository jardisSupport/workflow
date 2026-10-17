<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use DateTimeImmutable;
use DateTimeZone;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Scheduling\Clock\FixedClock;
use JardisSupport\Workflow\Data\Response\ContextResponse;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * TransformResponse: ContextResponse tree -> immutable DomainResponse, time from a PSR-20 clock.
 */
class TransformResponseTest extends TestCase
{
    private function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable('2026-01-15 10:30:00', new DateTimeZone('UTC')));
    }

    public function testIsFinalWithExactlyOnePublicEntryPoint(): void
    {
        $class = new ReflectionClass(TransformResponse::class);
        $public = array_values(array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => !$method->isConstructor()
        ));

        $this->assertTrue($class->isFinal());
        $this->assertCount(1, $public);
        $this->assertSame('__invoke', $public[0]->getName());
    }

    public function testSourceReadsNoWallClock(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(TransformResponse::class))->getFileName()
        );

        $this->assertSame(0, preg_match('/microtime|date\(|new \\\\?DateTime/', $source));
    }

    public function testMetaIsDeterministicWithAFixedClock(): void
    {
        $transform = new TransformResponse($this->clock(), 'v7');

        $response = $transform((new ContextResponse('order'))->addData('a', 1));

        $this->assertSame(
            [
                'duration' => 0.0,
                'contexts' => ['order'],
                'timestamp' => '2026-01-15T10:30:00+00:00',
                'version' => 'v7',
            ],
            $response->getMetadata()
        );
    }

    public function testDurationIsTheClockDifferenceInMillisecondsWithTwoDecimals(): void
    {
        $instants = [
            new DateTimeImmutable('2026-01-15 10:30:00.000000', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-15 10:30:01.500250', new DateTimeZone('UTC')),
        ];
        $clock = new class ($instants) implements ClockInterface {
            /** @param list<DateTimeImmutable> $instants */
            public function __construct(private array $instants)
            {
            }

            public function now(): DateTimeImmutable
            {
                return array_shift($this->instants) ?? throw new LogicException('clock exhausted');
            }
        };

        $response = (new TransformResponse($clock))(new ContextResponse('order'));

        $this->assertSame(1500.25, $response->getMetadata()['duration']);
        $this->assertSame('2026-01-15T10:30:01+00:00', $response->getMetadata()['timestamp']);
    }

    public function testStatusIsResolvedFromErrorsUnlessGiven(): void
    {
        $transform = new TransformResponse($this->clock());

        $this->assertSame(200, $transform(new ContextResponse('order'))->getStatus());
        $this->assertSame(
            400,
            $transform((new ContextResponse('order'))->addError('bad'))->getStatus()
        );
        $this->assertSame(
            404,
            $transform((new ContextResponse('order'))->addError('bad'), ResponseStatus::NotFound)->getStatus()
        );
    }

    public function testDataIsTheFlatPayloadOfTheOnlyDataCarryingContext(): void
    {
        $root = (new ContextResponse('order'))
            ->addResult((new ContextResponse('inventory'))->setData(['@type' => 'order', 'n' => 1]))
            ->addResult(new ContextResponse('warehouse'));

        $response = (new TransformResponse($this->clock()))($root);

        $this->assertSame(['@type' => 'order', 'n' => 1], $response->getData());
        $this->assertSame(['order', 'inventory', 'warehouse'], $response->getMetadata()['contexts']);
    }

    public function testMoreThanOneDataCarryingContextIsAmbiguous(): void
    {
        $root = (new ContextResponse('order'))
            ->addData('a', 1)
            ->addResult((new ContextResponse('inventory'))->addData('b', 2));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('2 contexts carry data (order, inventory)');

        (new TransformResponse($this->clock()))($root);
    }

    public function testEventsAndErrorsAreCollectedAcrossTheTreePerScope(): void
    {
        $internal = new \stdClass();
        $domain = new \stdClass();
        $root = (new ContextResponse('order'))
            ->addEvent($internal)
            ->addError('root error')
            ->addResult(
                (new ContextResponse('inventory'))
                    ->addEvent($domain, EventScope::Domain)
                    ->addError('child error')
            );

        $response = (new TransformResponse($this->clock()))($root);

        $this->assertSame(['order' => [$internal]], $response->getEvents(EventScope::Internal));
        $this->assertSame(['inventory' => [$domain]], $response->getEvents(EventScope::Domain));
        $this->assertSame(['order' => ['root error'], 'inventory' => ['child error']], $response->getErrors());
    }

    public function testSharedSubResultIsVisitedOnce(): void
    {
        $shared = (new ContextResponse('shared'))->addError('once');
        $root = (new ContextResponse('order'))->addResult($shared)->addResult($shared);

        $response = (new TransformResponse($this->clock()))($root);

        $this->assertSame(['shared' => ['once']], $response->getErrors());
        $this->assertSame(['order', 'shared'], $response->getMetadata()['contexts']);
    }
}
