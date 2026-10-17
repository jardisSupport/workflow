<?php

// Copy of the E3-P3 golden (builder commit 6ea3fe0cd), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler;

use Ecommerce\EcommerceContext;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Scheduling\Clock\SystemClock;
use JardisSupport\Validation\ObjectValidator;
use JardisSupport\Workflow\Handler\Response\TransformResponse;
use JardisSupport\Workflow\Handler\Start\CheckRequires;
use JardisSupport\Workflow\ProcessRunner;

/**
 * Wiring of the process OrderBulkQuote (hermetic): requires, runner, events, answer.
 */
final class OrderBulkQuoteHandler extends EcommerceContext
{
    public function __invoke(): DomainResponseInterface
    {
        $definition = (new OrderBulkQuoteDefinition())();
        (new CheckRequires())($this->resource(), $definition->getRequires());
        $runner = ProcessRunner::forKernel(
            $this->resource(),
            new ObjectValidator(registry: (new OrderBulkQuoteValidator())(), fieldPaths: true)
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
