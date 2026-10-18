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
 * Node ReviseExistingService of the process RegisterAndReviseServiceOptOut (call).
 *
 * Revidiert eine bestehende ServiceRegistration. Fehlt das Ziel, antwortet
 * der Prozess 404 mit dem deklarierten messageKey.
 *
 * Calls the facade Ops.Registry.ServiceRegistration, method UpdateServiceRegistration.
 */
final class ReviseExistingService extends OpsContext
{
    /**
     * @node-id rar2
     * @throws Throwable
     */
    public function __invoke(RegisterAndReviseServiceOptOutContext $ctx): NodeResult
    {
        /** @var DomainResponseInterface $response */
        $response = $this->handle(ServiceRegistration::class)->updateServiceRegistration($ctx->input()->revision);

        return (new MapResponse())($response);
    }
}
