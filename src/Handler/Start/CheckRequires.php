<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Start;

use JardisSupport\Contract\Kernel\DomainKernelInterface;
use JardisSupport\Workflow\Exception\ConfigurationException;

/**
 * Checks the resources a process declares as required against the kernel.
 *
 * A required name is the name of an optional kernel accessor (`auth`, `cache`, `messaging`, ...).
 * A name the kernel has no accessor for, or an accessor that answers null, is a configuration
 * error. The generated handler calls this before the run, the runner never does.
 */
final class CheckRequires
{
    /**
     * @throws ConfigurationException when a required resource is unknown or not provided
     * @param list<string> $requires
     */
    public function __invoke(DomainKernelInterface $kernel, array $requires): void
    {
        $accessors = [
            'cache' => static fn(): ?object => $kernel->cache(),
            'logger' => static fn(): ?object => $kernel->logger(),
            'eventDispatcher' => static fn(): ?object => $kernel->eventDispatcher(),
            'eventListenerRegistry' => static fn(): ?object => $kernel->eventListenerRegistry(),
            'httpClient' => static fn(): ?object => $kernel->httpClient(),
            'dbConnection' => static fn(): ?object => $kernel->dbConnection(),
            'mailer' => static fn(): ?object => $kernel->mailer(),
            'filesystem' => static fn(): ?object => $kernel->filesystem(),
            'messaging' => static fn(): ?object => $kernel->messaging(),
            'auth' => static fn(): ?object => $kernel->auth(),
        ];

        foreach ($requires as $name) {
            $accessor = $accessors[$name] ?? throw new ConfigurationException(sprintf(
                'Required resource "%s" is not a kernel accessor',
                $name
            ));
            $accessor() ?? throw new ConfigurationException(sprintf(
                'Required resource "%s" is not provided by the kernel',
                $name
            ));
        }
    }
}
