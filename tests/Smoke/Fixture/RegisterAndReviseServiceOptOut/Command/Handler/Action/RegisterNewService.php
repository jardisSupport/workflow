<?php

// Copy of the generat (builder commit df33f2953, tests/Builder/Generated), see tests/Smoke/SmokeTest.php. Do not edit by hand.
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Workflow\Data\NodeResult;
use JardisSupport\Workflow\Handler\Node\MapResponse;
use Ops\OpsContext;
use Ops\Registry\Model\ServiceRegistration\ServiceRegistration;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutContext;
use Throwable;

/**
 * Node RegisterNewService of the process RegisterAndReviseServiceOptOut (call).
 *
 * Legt die ServiceRegistration ueber die Aggregat-API an. Ein abgelehnter
 * Wert antwortet 400, ein doppelter Dienstname 409 — beide mit dem
 * deklarierten messageKey.
 *
 * Calls the facade Ops.Registry.ServiceRegistration, method ServiceRegistration.
 */
final class RegisterNewService extends OpsContext
{
    /**
     * @node-id rar1
     * @throws Throwable
     */
    public function __invoke(RegisterAndReviseServiceOptOutContext $ctx): NodeResult
    {
        /** @var DomainResponseInterface $response */
        $response = $this->handle(ServiceRegistration::class)->createServiceRegistration($ctx->input()->registration);

        return (new MapResponse())($response);
    }
}
