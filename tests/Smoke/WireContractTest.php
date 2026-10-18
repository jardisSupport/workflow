<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Smoke;

use Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler\OrderBulkQuoteHandler;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuote;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesDiscountItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteLinesItem;
use Ecommerce\Sales\Process\OrderBulkQuote\Command\OrderBulkQuoteShippingAddressItem;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\VoucherRedeemCheckActor;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\Handler\VoucherRedeemCheckHandler;
use Ecommerce\Sales\Process\VoucherRedeemCheck\Command\VoucherRedeemCheck;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Tests\Smoke\Support\FakeKernel;
use JardisSupport\Workflow\Tests\Support\EnvelopeJson;
use JardisSupport\Workflow\Tests\Support\FakeDomainResponse;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration;
use Ops\Registry\Model\ServiceRegistration\ServiceRegistration as RegistrationFacade;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutHandler;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\RegisterAndReviseServiceOptOut;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the package: the envelope bytes a generated process handler answers per status.
 *
 * `Contract/wire-contract.json` holds, for the eight cases 200, 200-empty (a last node without an answer of
 * its own: `data` carries only the `@type`), 400, 401, 404, 409, 422 and 500, the envelope (status, data, errors, meta) that the generated handlers of the smoke fixtures produce - the real runner,
 * the real `BuildResponseData` and `TransformResponse`, a frozen clock. The file is compared byte for byte;
 * it is also what a consumer (the Builder's generated handlers) has to reproduce, and it ships with the
 * package (the one `tests/` path that is not `export-ignore`, see `.gitattributes`).
 *
 * Rewriting the file is explicit: run this test with the env switch UPDATE_WIRE_CONTRACT=1, e.g.
 * `docker compose run --rm --no-deps -e UPDATE_WIRE_CONTRACT=1 phpcli vendor/bin/phpunit --bootstrap ./tests/bootstrap.php --filter WireContractTest /app/tests`.
 * A changed contract is a breaking change of the wire (major version).
 */
class WireContractTest extends TestCase
{
    private const FILE = __DIR__ . '/Contract/wire-contract.json';
    private const UUID = '3f2b8c1e-5d4a-4b7e-9c1d-0a1b2c3d4e5f';

    public function testWireContractFileHoldsEveryStatusAndMatchesTheGeneratedHandlersByteForByte(): void
    {
        $document = $this->document();
        $bytes = EnvelopeJson::encode($document) . "\n";

        if (getenv('UPDATE_WIRE_CONTRACT') === '1') {
            file_put_contents(self::FILE, $bytes);
        }

        $this->assertFileExists(self::FILE);
        $this->assertSame($bytes, (string) file_get_contents(self::FILE), 'wire contract drifted - rewrite only with UPDATE_WIRE_CONTRACT=1');
    }

    public function testEveryCaseAnswersItsOwnStatus(): void
    {
        $cases = $this->document()['cases'];

        $expected = [
            ['200', 200],
            ['200-empty', 200],
            ['400', 400],
            ['401', 401],
            ['404', 404],
            ['409', 409],
            ['422', 422],
            ['500', 500],
        ];

        $this->assertSame(array_column($expected, 0), array_map('strval', array_keys($cases)));
        foreach ($expected as [$key, $status]) {
            $this->assertSame($status, $cases[$key]['envelope']['status'], 'case ' . $key);
        }
        $this->assertSame(['@type' => 'voucherRedeemCheck'], $cases['200-empty']['envelope']['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'contract' => 'jardissupport/workflow wire contract: the envelope per status that a generated process handler answers',
            'provenance' => [
                'source' => 'tests/Smoke/Fixture (copies of the generat, builder commit df33f2953) run through the real runner',
                'clock' => 'frozen at 2026-01-15T10:30:00+00:00 (meta.duration 0.0)',
                'encoding' => 'EnvelopeJson::FLAGS, empty data/errors/meta as {}',
            ],
            'cases' => [
                '200' => $this->case('OrderBulkQuote', 'valid input, one action node answers a typed response', $this->success()),
                '200-empty' => $this->case('VoucherRedeemCheck', 'rule node is the last node and answers no data of its own, the answer is empty', $this->successWithoutAnswer()),
                '400' => $this->case('OrderBulkQuote', 'start check: malformed input fields, no node runs', $this->invalidInput()),
                '401' => $this->case('VoucherRedeemCheck', 'start check: the declared actor is missing, no node runs', $this->actorMissing()),
                '404' => $this->case('RegisterAndReviseServiceOptOut', 'second call node: the callee answers 404, declared key', $this->revisionTargetMissing()),
                '409' => $this->case('RegisterAndReviseServiceOptOut', 'first call node: the callee answers 409, declared key', $this->registrationClash()),
                '422' => $this->case('VoucherRedeemCheck', 'rule node: the voucher has no root, rule violation', $this->ruleViolation()),
                '500' => $this->case('RegisterAndReviseServiceOptOut', 'first call node: the callee throws, empty data', $this->calleeDown()),
            ],
        ];
    }

    /**
     * @return array{process: string, scenario: string, envelope: array<string, mixed>}
     */
    private function case(string $process, string $scenario, DomainResponseInterface $response): array
    {
        return ['process' => $process, 'scenario' => $scenario, 'envelope' => EnvelopeJson::envelope($response)];
    }

    private function success(): DomainResponseInterface
    {
        return (new OrderBulkQuoteHandler(new FakeKernel(), $this->quote(), 'v1'))();
    }

    private function successWithoutAnswer(): DomainResponseInterface
    {
        $actor = new VoucherRedeemCheckActor('a-1', 'ak-1', 'sess-1', 'tenant-1');

        return (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('SPRING'), '', $actor))();
    }

    private function invalidInput(): DomainResponseInterface
    {
        return (new OrderBulkQuoteHandler(new FakeKernel(), $this->quote('abc', 'fax', 'not-a-uuid')))();
    }

    private function actorMissing(): DomainResponseInterface
    {
        return (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('SPRING')))();
    }

    private function ruleViolation(): DomainResponseInterface
    {
        $actor = new VoucherRedeemCheckActor('a-1', 'ak-1', 'sess-1', 'tenant-1');

        return (new VoucherRedeemCheckHandler(new FakeKernel(), new VoucherRedeemCheck('unknown'), '', $actor))();
    }

    private function registrationClash(): DomainResponseInterface
    {
        return $this->register(new FakeDomainResponse(409), new FakeDomainResponse(200));
    }

    private function revisionTargetMissing(): DomainResponseInterface
    {
        return $this->register(new FakeDomainResponse(201), new FakeDomainResponse(404));
    }

    private function calleeDown(): DomainResponseInterface
    {
        return $this->register(null, new FakeDomainResponse(200));
    }

    private function register(?DomainResponseInterface $create, DomainResponseInterface $update): DomainResponseInterface
    {
        $kernel = new FakeKernel();
        $kernel->register(RegistrationFacade::class, new RegistrationFacade(
            static fn(ServiceRegistration $c): DomainResponseInterface => $create ?? throw new \RuntimeException('callee down'),
            static fn(UpdateServiceRegistration $c): DomainResponseInterface => $update
        ));
        $input = new RegisterAndReviseServiceOptOut(new ServiceRegistration('billing'), new UpdateServiceRegistration('billing'));

        return (new RegisterAndReviseServiceOptOutHandler($kernel, $input))();
    }

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
}
