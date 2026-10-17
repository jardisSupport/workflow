<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Data\ProcessContext;
use JardisSupport\Workflow\Data\ProcessDefinition;
use JardisSupport\Workflow\Data\Route;
use JardisSupport\Workflow\ProcessRunner;
use JardisSupport\Workflow\Tests\Support\Db\Ledger;
use JardisSupport\Workflow\Tests\Support\Db\RecordingConnection;
use JardisSupport\Workflow\Tests\Support\Process\Order\OrderValidation;
use PHPUnit\Framework\TestCase;

/**
 * Set-up of the integration tests over SQLite with the real ObjectValidator.
 */
abstract class TransactionTestCase extends TestCase
{
    protected RecordingConnection $connection;
    protected Ledger $ledger;

    protected function setUp(): void
    {
        $pdo = Ledger::pdo();
        $this->ledger = new Ledger($pdo);
        $this->connection = new RecordingConnection($pdo);
    }

    protected function runnerOn(?DbConnectionInterface $connection = null): ProcessRunner
    {
        return new ProcessRunner($connection ?? $this->connection, OrderValidation::validator());
    }

    /**
     * @param array<string, callable(ProcessContext): NodeResult> $nodes
     * @param list<string> $built
     * @return \Closure(class-string): (callable(ProcessContext): NodeResult)
     */
    protected function factory(array $nodes, array &$built = []): \Closure
    {
        return static function (string $class) use ($nodes, &$built): callable {
            $built[] = $class;

            return $nodes[$class] ?? throw new \LogicException('no node registered for ' . $class);
        };
    }

    /**
     * A node that writes one ledger row and answers with the given data.
     *
     * @param array<string, mixed> $data
     * @return callable(ProcessContext): NodeResult
     */
    protected function writes(string $note, array $data = []): callable
    {
        return function (ProcessContext $context) use ($note, $data): NodeResult {
            $this->ledger->add($note);

            return NodeResult::ok($data);
        };
    }

    /**
     * A node that writes one ledger row and then rejects.
     *
     * @return callable(ProcessContext): NodeResult
     */
    protected function writesThenRejects(string $note, ResponseStatus $status, string $key): callable
    {
        return function (ProcessContext $context) use ($note, $status, $key): NodeResult {
            $this->ledger->add($note);

            return NodeResult::reject($status, $key);
        };
    }

    /**
     * A sub-process node as the generated code writes it: it runs the inner process with the
     * surrounding context as parent and maps the inner outcome to its own result - the events travel
     * on, a 4xx is rejected again, a 5xx throws the cause, and `$onReject` (a dev decision) may route
     * a 4xx instead.
     *
     * @param \Closure(class-string): (callable(ProcessContext): NodeResult) $innerNodes
     * @return callable(ProcessContext): NodeResult
     */
    protected function subProcess(
        ProcessRunner $runner,
        ProcessDefinition $inner,
        \Closure $innerNodes,
        ?Route $onReject = null,
        ?object $input = null
    ): callable {
        return static function (ProcessContext $context) use ($runner, $inner, $innerNodes, $onReject, $input): NodeResult {
            $outcome = $runner->run($inner, $input ?? new \stdClass(), null, $innerNodes, $context);
            $status = $outcome->status();
            if ($status->value >= 500) {
                throw $outcome->cause() ?? new \RuntimeException('sub-process failed');
            }
            if ($status->value >= 400) {
                return $onReject !== null
                    ? NodeResult::route($onReject)
                    : NodeResult::reject($status, 'sub.rejected', [], $outcome->errors());
            }

            return NodeResult::ok((array) $outcome->response())->withEvents(...$outcome->events());
        };
    }
}
