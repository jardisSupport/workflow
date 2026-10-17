<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Integration\Response;

use DateTimeImmutable;
use DateTimeZone;
use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Scheduling\Clock\FixedClock;
use JardisSupport\Workflow\Data\Failure\Conflict;
use JardisSupport\Workflow\Data\Failure\FailureForm;
use JardisSupport\Workflow\Data\Failure\NotFound;
use JardisSupport\Workflow\Data\Failure\RuleViolation;
use JardisSupport\Workflow\Data\Failure\ValidationFailure;
use JardisSupport\Workflow\Data\ProcessOutcome;
use JardisSupport\Workflow\Data\Response\ContextResponse;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use JardisSupport\Workflow\Tests\Support\EnvelopeCases;
use JardisSupport\Workflow\Tests\Support\EnvelopeJson;
use PHPUnit\Framework\TestCase;

/**
 * Envelope parity with the pre-move generated classes: the fixtures were recorded once from
 * builder commit e72b2b528 and are frozen; the moved code must reproduce their bytes.
 */
class EnvelopeParityTest extends TestCase
{
    private const BUILDER_COMMIT = 'e72b2b5281bb7e41b89a5a3b95ad093657e627e8';

    /** SHA-256 of each frozen fixture file (the file as committed). */
    private const FROZEN = [
        'success' => 'ae1217dabb0bb5f068d8808589a2656139ba8653619f4584c2a349443cb4dc33',
        'validation' => 'a1516244180d35567d9e8bdf6e3b55050aa67a1c9462ab00b9608e80cb8a880d',
        'notFound' => '286f689097f547be0d290e75b976e7a2380bf9dbb4c78946919eaebb378e781c',
        'conflict' => '0fe87a19c6299bbaa7e0f03b9ba171631730612f5137cebeb25d220aefe96de9',
        'ruleViolation' => '6871e54ba43b5871c164d56fa41f5163420c2f61d58a69aa412dc4279dd64229',
    ];

    public function testSuccessEnvelopeIsByteIdentical(): void
    {
        $response = $this->transform('success', null);

        $this->assertSame(EnvelopeJson::encode($this->fixture('success')->body), $this->bytes($response));
    }

    public function testValidationEnvelopeIsByteIdentical(): void
    {
        $args = $this->args('validation');
        $data = $this->data(new ValidationFailure('order.invalid', $args['fields']));

        $response = $this->transform('validation', $data);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(EnvelopeJson::encode($this->fixture('validation')->body), $this->bytes($response));
    }

    public function testNotFoundEnvelopeIsByteIdentical(): void
    {
        $args = $this->args('notFound');
        $data = $this->data(new NotFound('order.notFound', $args['context']));

        $response = $this->transform('notFound', $data);

        $this->assertSame(404, $response->getStatus());
        $this->assertSame(EnvelopeJson::encode($this->fixture('notFound')->body), $this->bytes($response));
    }

    public function testConflictEnvelopeIsByteIdentical(): void
    {
        $args = $this->args('conflict');
        $data = $this->data(new Conflict(
            'order.conflict',
            $args['context'],
            $args['reason'],
            $args['aggregate'],
            $args['violated']
        ));

        $response = $this->transform('conflict', $data);

        $this->assertSame(409, $response->getStatus());
        $this->assertSame(EnvelopeJson::encode($this->fixture('conflict')->body), $this->bytes($response));
    }

    public function testRuleViolationEnvelopeIsByteIdentical(): void
    {
        $args = $this->args('ruleViolation');
        $data = $this->data(new RuleViolation($args['rule'], $args['messageKey'], $args['context']));

        $response = $this->transform('ruleViolation', $data);

        $this->assertSame(422, $response->getStatus());
        $this->assertSame(EnvelopeJson::encode($this->fixture('ruleViolation')->body), $this->bytes($response));
        $this->assertStringContainsString('"context": {}', $this->bytes($response));
    }

    public function testFixtureHeaderCarriesCommitSourceHashesAndBodyHash(): void
    {
        foreach (array_keys(self::FROZEN) as $name) {
            $fixture = $this->fixture($name);
            $provenance = $fixture->_provenance;

            $this->assertSame(self::BUILDER_COMMIT, $provenance->builderCommit, $name);
            $this->assertCount(7, (array) $provenance->sourceSha256, $name);
            foreach ((array) $provenance->sourceSha256 as $path => $hash) {
                $this->assertStringStartsWith('tests/Builder/Generated/Domain/Ecommerce/Response/', $path);
                $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash, $name);
            }
            $this->assertSame(hash('sha256', EnvelopeJson::encode($fixture->body)), $provenance->bodySha256, $name);
            $this->assertSame(hash('sha256', EnvelopeJson::encode($fixture->events)), $provenance->eventsSha256, $name);
        }
    }

    public function testFixturesAreFrozen(): void
    {
        foreach (self::FROZEN as $name => $hash) {
            $this->assertSame($hash, hash_file('sha256', $this->path($name)), $name . '.json changed');
        }
    }

    public function testTreeWalkYieldsTheRecordedStructureWithoutTouchingTheInput(): void
    {
        $definition = EnvelopeCases::definitions()['success'];
        $root = EnvelopeCases::build(ContextResponse::class, $definition['tree']);
        $before = $this->snapshot($root);

        $response = (new TransformResponse($this->clock(), $definition['version']))($root);

        $this->assertSame($before, $this->snapshot($root));
        $this->assertSame(EnvelopeJson::encode($this->fixture('success')->body), $this->bytes($response));

        $recorded = $this->fixture('success')->events;
        $actual = [];
        foreach (EventScope::cases() as $scope) {
            $byContext = $response->getEvents($scope);
            if ($byContext !== []) {
                $actual[$scope->value] = $byContext;
            }
        }
        $this->assertSame(
            EnvelopeJson::encode($recorded),
            EnvelopeJson::encode(json_decode(EnvelopeJson::encode((object) $actual)))
        );
    }

    private function transform(string $name, ?array $formData): DomainResponseInterface
    {
        $definition = EnvelopeCases::definitions()[$name];
        $root = EnvelopeCases::build(ContextResponse::class, $definition['tree'], $formData);
        $status = $definition['status'] === null ? null : ResponseStatus::from($definition['status']);

        return (new TransformResponse($this->clock(), $definition['version']))($root, $status);
    }

    /**
     * The `data` of the failure answer, built by the package from the failure form.
     *
     * @return array<string, mixed>
     */
    private function data(FailureForm $form): array
    {
        return (new BuildResponseData())(new ProcessOutcome($form->status(), $form), 'order');
    }

    private function bytes(DomainResponseInterface $response): string
    {
        return EnvelopeJson::encode(EnvelopeJson::envelope($response));
    }

    private function clock(): FixedClock
    {
        return new FixedClock(new DateTimeImmutable(EnvelopeCases::FIXED_AT, new DateTimeZone('UTC')));
    }

    /**
     * @return array<string, mixed>
     */
    private function args(string $name): array
    {
        return EnvelopeCases::definitions()[$name]['tree']['form']['args'];
    }

    private function path(string $name): string
    {
        return dirname(__DIR__, 2) . '/Fixture/envelope/' . $name . '.json';
    }

    private function fixture(string $name, bool $assoc = false): object|array
    {
        return json_decode((string) file_get_contents($this->path($name)), $assoc, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Whole observable state of a ContextResponse tree.
     *
     * @return array<string, mixed>
     */
    private function snapshot(ContextResponseInterface $node): array
    {
        return [
            'data' => $node->getData(),
            'events' => $node->getEvents(),
            'internal' => $node->getEvents(EventScope::Internal),
            'domain' => $node->getEvents(EventScope::Domain),
            'errors' => $node->getErrors(),
            'results' => array_map(fn (ContextResponseInterface $child): array => $this->snapshot($child), $node->getResults()),
        ];
    }
}
