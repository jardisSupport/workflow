<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteDefinition;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteHandler;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesDiscountItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteShippingAddressItem;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\VoucherRedeemCheckActor;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\VoucherRedeemCheckHandler;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\VoucherRedeemCheck;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Tests\Smoke\Support\FakeKernel;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\ServiceRegistration as RegistrationFacade;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutDefinition;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutHandler;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\RegisterAndReviseServiceOptOut;
use PHPUnit\Framework\TestCase;

/**
 * Smoke of the generat: the generated process classes (copies under `Fixture/`, builder commit
 * df33f2953) run as PHP against the working tree of the package - the real runner, the real validator,
 * a fake kernel with a SQLite PDO. The node bodies are filled in the Fixture copy (the generat's stub
 * body throws); everything else of the generat is untouched.
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
            ['@type' => 'orderBulkQuote', 'lineCount' => 2, 'discountedLines' => 1, 'city' => 'Berlin', 'voucherCount' => 3],
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

    public function testRegisterAndReviseRunsBothCallNodesThroughTheFacadeSeam(): void
    {
        $kernel = new FakeKernel();
        $calls = [];
        $this->facade($kernel, $calls, 201, 200);
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        $response = (new RegisterAndReviseServiceOptOutHandler($kernel, $input))();

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['create:billing', 'update:billing'], $calls, 'both call nodes used the facade the kernel hands out');
        $this->assertSame([], $response->getEvents(EventScope::Domain));
    }

    public function testDeclaredRejectsEndTheRunWithTheirStatus(): void
    {
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        $clash = new FakeKernel();
        $calls = [];
        $this->facade($clash, $calls, 409, 200);
        $response = (new RegisterAndReviseServiceOptOutHandler($clash, $input))();
        $this->assertSame(409, $response->getStatus());
        $this->assertSame(['create:billing'], $calls, 'the second node never runs after a reject');
        $this->assertSame('registry.register_service_clash', $response->getData()['messageKey'] ?? null);

        $missing = new FakeKernel();
        $calls = [];
        $this->facade($missing, $calls, 201, 404);
        $response = (new RegisterAndReviseServiceOptOutHandler($missing, $input))();
        $this->assertSame(404, $response->getStatus());
        $this->assertSame('registry.revise_target_missing', $response->getData()['messageKey'] ?? null);
    }

    public function testThrowingSeamAnswers500AndNoEventLeaks(): void
    {
        $kernel = new FakeKernel();
        $kernel->register(RegistrationFacade::class, new RegistrationFacade(
            static fn(ServiceRegistration $c): DomainResponseInterface => throw new \RuntimeException('callee down'),
            static fn(UpdateServiceRegistration $c): DomainResponseInterface => new FakeDomainResponse(200)
        ));
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        $response = (new RegisterAndReviseServiceOptOutHandler($kernel, $input))();

        $this->assertSame(ResponseStatus::InternalError->value, $response->getStatus());
        $this->assertSame([], $response->getEvents(EventScope::Domain));
    }

    public function testVoucherRedeemCheckWithoutActorAnswers401BeforeAnyNode(): void
    {
        $response = (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('SPRING')))();

        $this->assertSame(401, $response->getStatus());
    }

    public function testVoucherRedeemCheckPassesAndRejectsWith422(): void
    {
        $actor = new VoucherRedeemCheckActor('a-1', 'ak-1', 'sess-1', 'tenant-1');

        $passed = (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('SPRING'), '', $actor))();
        $this->assertSame(200, $passed->getStatus());

        $unknown = (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('unknown'), '', $actor))();
        $this->assertSame(422, $unknown->getStatus());
        $this->assertSame('sales.voucher.notFound', $unknown->getData()['messageKey'] ?? null);

        $expired = (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('expired'), '', $actor))();
        $this->assertSame(422, $expired->getStatus());
        $this->assertSame('VoucherIsRedeemable', $expired->getData()['rule'] ?? null);
    }

    /**
     * Registers the facade of the aggregate on the kernel; the commands are noted in $calls.
     *
     * @param list<string> $calls
     */
    private function facade(FakeKernel $kernel, array &$calls, int $createStatus, int $updateStatus): void
    {
        $kernel->register(RegistrationFacade::class, new RegistrationFacade(
            static function (ServiceRegistration $c) use (&$calls, $createStatus): DomainResponseInterface {
                $calls[] = 'create:' . $c->name;

                return new FakeDomainResponse($createStatus);
            },
            static function (UpdateServiceRegistration $c) use (&$calls, $updateStatus): DomainResponseInterface {
                $calls[] = 'update:' . $c->name;

                return new FakeDomainResponse($updateStatus);
            }
        ));
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
