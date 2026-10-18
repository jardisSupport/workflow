<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ecommerce\Sales\Process\OrderBulkQuote\Command\Handler;

use Ecommerce\EcommerceContext;
use Exception;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use JardisSupport\Validation\ObjectValidator;
use JardisSupport\Workflow\Handler\Response\BuildResponseData;
use JardisSupport\Workflow\Handler\Start\CheckRequires;
use JardisSupport\Workflow\ProcessRunner;
use Throwable;

/**
 * Wiring of the process OrderBulkQuote (hermetic): requires, runner, events, answer.
 */
final class OrderBulkQuoteHandler extends EcommerceContext
{
    /**
     * @throws Exception
     * @throws Throwable
     */
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
            $this->actor(),
            fn(string $class): object => $this->handle($class)
        );
        foreach ($outcome->events() as $event) {
            $this->result()->addEvent($event, EventScope::Domain);
        }
        foreach ($outcome->errors() as $error) {
            $this->result()->addError($error);
        }
        $this->result()->setData((new BuildResponseData())($outcome, $definition->getName()));

        return ($this->transformer())($this->result(), $outcome->status());
    }
}
