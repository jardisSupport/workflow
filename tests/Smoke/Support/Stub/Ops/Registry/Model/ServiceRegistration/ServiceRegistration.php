<?php

declare(strict_types=1);

namespace Ops\Registry\Model\ServiceRegistration;

use JardisSupport\Contract\Kernel\DomainResponseInterface;
use Ops\Registry\Model\ServiceRegistration\Command\ServiceRegistration as CreateCommand;
use Ops\Registry\Model\ServiceRegistration\Command\UpdateServiceRegistration as UpdateCommand;

/**
 * Stub of the generated write facade of the aggregate ServiceRegistration: the answers of its two
 * commands are whatever the test hands in.
 */
final class ServiceRegistration
{
    /**
     * @param \Closure(CreateCommand): DomainResponseInterface $create
     * @param \Closure(UpdateCommand): DomainResponseInterface $update
     */
    public function __construct(private readonly \Closure $create, private readonly \Closure $update)
    {
    }

    public function createServiceRegistration(CreateCommand $command): DomainResponseInterface
    {
        return ($this->create)($command);
    }

    public function updateServiceRegistration(UpdateCommand $command): DomainResponseInterface
    {
        return ($this->update)($command);
    }
}
