<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Builder;

use JardisSupport\Contract\Workflow\WorkflowConfigInterface;
use JardisSupport\Contract\Workflow\WorkflowNodeBuilderInterface;
use JardisSupport\Workflow\WorkflowResult;

/**
 * Builder for configuring transitions on a workflow node.
 */
class WorkflowNodeBuilder implements WorkflowNodeBuilderInterface
{
    public function __construct(
        private readonly WorkflowBuilder $builder
    ) {
    }

    public function onSuccess(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_SUCCESS, $handlerClass);
        return $this;
    }

    public function onFail(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_FAIL, $handlerClass);
        return $this;
    }

    public function onError(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_ERROR, $handlerClass);
        return $this;
    }

    public function onTimeout(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_TIMEOUT, $handlerClass);
        return $this;
    }

    public function onRetry(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_RETRY, $handlerClass);
        return $this;
    }

    public function onSkip(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_SKIP, $handlerClass);
        return $this;
    }

    public function onPending(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_PENDING, $handlerClass);
        return $this;
    }

    public function onCancel(string $handlerClass): self
    {
        $this->builder->addTransition(WorkflowResult::ON_CANCEL, $handlerClass);
        return $this;
    }

    public function node(string $handlerClass): WorkflowNodeBuilderInterface
    {
        return $this->builder->node($handlerClass);
    }

    public function build(): WorkflowConfigInterface
    {
        return $this->builder->build();
    }
}
