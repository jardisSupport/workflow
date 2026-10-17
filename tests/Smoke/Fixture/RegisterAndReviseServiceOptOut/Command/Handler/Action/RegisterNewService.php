<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
// Only the body of __invoke() differs: it is the dev area of the node, filled for the smoke (stub: throws).
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutContext;

/**
 * Node RegisterNewService of the process RegisterAndReviseServiceOptOut (call).
 *
 * Legt die ServiceRegistration ueber die Aggregat-API an. Ein abgelehnter
 * Wert antwortet 400, ein doppelter Dienstname 409 — beide mit dem
 * deklarierten messageKey.
 *
 * Calls the facade Ops.Registry.ServiceRegistration, method ServiceRegistration.
 */
final class RegisterNewService
{
    /**
     * @param \Closure(class-string, object): DomainResponseInterface $call The kernel seam of the handler
     */
    public function __construct(protected readonly \Closure $call)
    {
    }

    /**
     * @node-id rar1
     */
    public function __invoke(RegisterAndReviseServiceOptOutContext $ctx): NodeResult
    {
        $answer = ($this->call)(ServiceRegistration::class, $ctx->input()->registration);
        if (!$answer->isSuccess()) {
            return NodeResult::reject(
                ResponseStatus::from($answer->getStatus()),
                'registry.register_service_clash'
            );
        }

        return NodeResult::ok(['registered' => $ctx->input()->registration->name]);
    }
}
