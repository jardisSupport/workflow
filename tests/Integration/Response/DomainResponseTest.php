<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Response\DomainResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DomainResponse: the immutable final answer.
 */
class DomainResponseTest extends TestCase
{
    public function testExposesStatusDataErrorsAndMetadata(): void
    {
        $response = new DomainResponse(
            ResponseStatus::NotFound,
            ['@type' => 'notFound'],
            [],
            ['order' => ['gone']],
            ['version' => 'v1']
        );

        $this->assertInstanceOf(DomainResponseInterface::class, $response);
        $this->assertSame(404, $response->getStatus());
        $this->assertSame(['@type' => 'notFound'], $response->getData());
        $this->assertSame(['order' => ['gone']], $response->getErrors());
        $this->assertSame(['version' => 'v1'], $response->getMetadata());
    }

    public function testIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(DomainResponse::class))->isReadOnly());
    }

    #[DataProvider('successStatuses')]
    public function testSuccessStatusesAreSuccess(ResponseStatus $status, bool $expected): void
    {
        $this->assertSame($expected, (new DomainResponse($status, [], [], [], []))->isSuccess());
    }

    /**
     * @return array<string, array{ResponseStatus, bool}>
     */
    public static function successStatuses(): array
    {
        return [
            'success' => [ResponseStatus::Success, true],
            'created' => [ResponseStatus::Created, true],
            'noContent' => [ResponseStatus::NoContent, true],
            'validation' => [ResponseStatus::ValidationError, false],
            'notFound' => [ResponseStatus::NotFound, false],
            'conflict' => [ResponseStatus::Conflict, false],
            'ruleViolation' => [ResponseStatus::RuleViolation, false],
        ];
    }

    public function testEventsByScopeAreReturnedPerScopeAndCollapsedWithoutScope(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $c = new \stdClass();
        $response = new DomainResponse(
            ResponseStatus::Success,
            [],
            [],
            [],
            [],
            [
                'internal' => ['order' => [$a], 'inventory' => [$b]],
                'domain' => ['order' => [$c]],
            ]
        );

        $this->assertSame(['order' => [$a], 'inventory' => [$b]], $response->getEvents(EventScope::Internal));
        $this->assertSame(['order' => [$c]], $response->getEvents(EventScope::Domain));
        $this->assertSame(['order' => [$a, $c], 'inventory' => [$b]], $response->getEvents());
    }

    public function testLegacyFlatEventsAreInternalAndIgnoredWhenScopedEventsAreGiven(): void
    {
        $legacy = new \stdClass();
        $scoped = new \stdClass();

        $fromLegacy = new DomainResponse(ResponseStatus::Success, [], ['order' => [$legacy]], [], []);
        $this->assertSame(['order' => [$legacy]], $fromLegacy->getEvents(EventScope::Internal));
        $this->assertSame([], $fromLegacy->getEvents(EventScope::Domain));

        $both = new DomainResponse(
            ResponseStatus::Success,
            [],
            ['order' => [$legacy]],
            [],
            [],
            ['domain' => ['order' => [$scoped]]]
        );
        $this->assertSame([], $both->getEvents(EventScope::Internal));
        $this->assertSame(['order' => [$scoped]], $both->getEvents());
    }

    public function testWithoutEventsEverythingIsEmpty(): void
    {
        $response = new DomainResponse(ResponseStatus::Success, [], [], [], []);

        $this->assertSame([], $response->getEvents());
        $this->assertSame([], $response->getEvents(EventScope::Internal));
    }
}
