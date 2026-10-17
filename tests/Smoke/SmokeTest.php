<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteDefinition;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteHandler;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesDiscountItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteShippingAddressItem;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Tests\Smoke\Support\FakeKernel;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutDefinition;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutHandler;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\RegisterAndReviseServiceOptOut;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Event\ServiceRevised;
use PHPUnit\Framework\TestCase;

/**
 * Smoke of the E3-P3 goldens: the generated process classes (copies under `Fixture/`, builder commit
 * 6ea3fe0cd) run as PHP against the working tree of the package - the real runner, the real validator,
 * a fake kernel with a SQLite PDO. The node bodies are filled in the Fixture copy (the generat's stub
 * bodies throw); everything else of the generat is untouched.
 */
class SmokeTest extends TestCase
{
    private const UUID = '3f2b8c1e-5d4a-4b7e-9c1d-0a1b2c3d4e5f';

    private function quote(string $unitPrice = '12.50', string $channel = 'email', string $serial = self::UUID): OrderBulkQuote
    {
        return new OrderBulkQuote(
            [$channel],
            [
                new OrderBulkQuoteLinesItem(new OrderBulkQuoteLinesDiscountItem('10', null), 2, [$serial], 'SKU-1', $unitPrice),
                new OrderBulkQuoteLinesItem(null, 1, null, 'SKU-2', '3'),
            ],
            'buyer@example.test',
            new OrderBulkQuoteShippingAddressItem('Berlin', null, 'Unter den Linden 1'),
            ['V1', 'V2', 'V3']
        );
    }

    public function testBothDefinitionsBuild(): void
    {
        $bulk = (new OrderBulkQuoteDefinition())();
        $this->assertTrue($bulk->isBuilt());
        $this->assertSame('OrderBulkQuote', $bulk->getName());
        $this->assertTrue($bulk->isTransactional());

        $register = (new RegisterAndReviseServiceOptOutDefinition())();
        $this->assertTrue($register->isBuilt());
        $this->assertFalse($register->isTransactional());
    }

    public function testBulkQuoteRunsToTheTypedAnswerAndCommits(): void
    {
        $kernel = new FakeKernel();

        $response = (new OrderBulkQuoteHandler($kernel, $this->quote(), 'v1'))();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(
            ['lineCount' => 2, 'discountedLines' => 1, 'city' => 'Berlin', 'voucherCount' => 3],
            $this->payload($response)
        );
        $this->assertSame('v1', $response->getMetadata()['version']);
        $this->assertFalse($kernel->pdo()->inTransaction());
    }

    public function testBulkQuoteWithRejectedFieldsAnswers400BeforeAnyNode(): void
    {
        $response = (new OrderBulkQuoteHandler(
            new FakeKernel(),
            $this->quote(unitPrice: 'abc', channel: 'fax', serial: 'not-a-uuid')
        ))();

        $this->assertSame(400, $response->getStatus());
        $this->assertNotSame([], $response->getErrors());
        $this->assertArrayNotHasKey('lineCount', $this->payload($response));
    }

    public function testRegisterAndReviseCollectsTheEventAsDomainEvent(): void
    {
        $kernel = new FakeKernel();
        $calls = [];
        $kernel->onHandle(function (string $class, mixed ...$parameters) use (&$calls): DomainResponseInterface {
            $calls[] = [$class, $parameters];

            return new FakeDomainResponse(201);
        });
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        $response = (new RegisterAndReviseServiceOptOutHandler($kernel, $input))();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame([[ServiceRegistration::class, [$input->registration]]], $calls, 'the call node used the kernel seam');
        $events = $response->getEvents(EventScope::Domain);
        $collected = array_merge(...array_values($events));
        $this->assertCount(1, $collected);
        $this->assertInstanceOf(ServiceRevised::class, $collected[0]);
        $this->assertSame('billing', $collected[0]->name);
    }

    public function testDeclaredRejectsEndTheRunWithTheirStatus(): void
    {
        $clash = new FakeKernel();
        $clash->onHandle(static fn(string $class, mixed ...$p): DomainResponseInterface => new FakeDomainResponse(409));
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));
        $this->assertSame(409, (new RegisterAndReviseServiceOptOutHandler($clash, $input))()->getStatus());

        $missing = new FakeKernel();
        $missing->onHandle(static fn(string $class, mixed ...$p): DomainResponseInterface => new FakeDomainResponse(201));
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('missing'));
        $this->assertSame(404, (new RegisterAndReviseServiceOptOutHandler($missing, $input))()->getStatus());
    }

    public function testThrowingSeamAnswers500AndNoEventLeaks(): void
    {
        $kernel = new FakeKernel();
        $kernel->onHandle(static function (string $class, mixed ...$p): DomainResponseInterface {
            throw new \RuntimeException('callee down');
        });
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        $response = (new RegisterAndReviseServiceOptOutHandler($kernel, $input))();

        $this->assertSame(ResponseStatus::InternalError->value, $response->getStatus());
        $this->assertSame([], $response->getEvents(EventScope::Domain));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DomainResponseInterface $response): array
    {
        $data = $response->getData();
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $value);
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }
}
