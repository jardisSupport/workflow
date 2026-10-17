<?php

// Copy of the E3-P3 golden (builder commit 6ea3fe0cd), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Scheduling\Clock\SystemClock;
use JardisSupport\Validation\ObjectValidator;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use JardisSupport\Workflow\Handler\Start\CheckRequires;
use JardisSupport\Workflow\ProcessRunner;
use Ops\OpsContext;

/**
 * Wiring of the process RegisterAndReviseServiceOptOut (hermetic): requires, runner, events, answer.
 */
final class RegisterAndReviseServiceOptOutHandler extends OpsContext
{
    public function __invoke(): DomainResponseInterface
    {
        $definition = (new RegisterAndReviseServiceOptOutDefinition())();
        (new CheckRequires())($this->resource(), $definition->getRequires());
        $runner = ProcessRunner::forKernel(
            $this->resource(),
            new ObjectValidator(registry: (new RegisterAndReviseServiceOptOutValidator())(), fieldPaths: true)
        );
        $outcome = $runner->run(
            $definition,
            $this->payload(),
            null,
            fn(string $class): callable => new $class(fn(string $h, object $d) => $this->handle($h, $d))
        );
        foreach ($outcome->events() as $event) {
            $this->result()->addEvent($event, EventScope::Domain);
        }
        foreach ($outcome->errors() as $error) {
            $this->result()->addError($error);
        }
        $this->result()->setData(get_object_vars($outcome->response()));

        return (new TransformResponse(new SystemClock(), $this->version()))($this->result(), $outcome->status());
    }
}
