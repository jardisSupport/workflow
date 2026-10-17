<?php

// Copy of the E3-P3 golden (builder commit 61b275eec), see tests/Smoke/SmokeTest.php. Do not edit by hand.
// Only the body of __invoke() differs: it is the dev area of the node, filled for the smoke (stub: throws).
declare(strict_types=1);

namespace Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\Action;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\NodeResult;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Command\Handler\RegisterAndReviseServiceOptOutContext;
use Ops\Registry\Process\RegisterAndReviseServiceOptOut\Event\ServiceRevised;

/**
 * Node ReviseExistingService of the process RegisterAndReviseServiceOptOut (call).
 *
 * Revidiert eine bestehende ServiceRegistration. Fehlt das Ziel, antwortet
 * der Prozess 404 mit dem deklarierten messageKey.
 *
 * Calls the facade Ops.Registry.ServiceRegistration, method UpdateServiceRegistration.
 */
final class ReviseExistingService
{
    /**
     * @param \Closure(class-string, object): DomainResponseInterface $call The kernel seam of the handler
     */
    public function __construct(protected readonly \Closure $call)
    {
    }

    /**
     * @node-id rar2
     */
    public function __invoke(RegisterAndReviseServiceOptOutContext $ctx): NodeResult
    {
        $name = $ctx->input()->revision->name;
        if ($name === 'missing') {
            return NodeResult::reject(ResponseStatus::NotFound, 'registry.revise_target_missing');
        }

        return NodeResult::ok(['revised' => $name])->withEvents(new ServiceRevised($name));
    }
}
