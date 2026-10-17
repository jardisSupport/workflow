<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Builder\WorkflowConfigBuilder;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Declarative description of one process: input, actor, start contract, node graph and per-node declarations.
 *
 * Built fluently, then frozen by {@see self::build()}: afterwards every mutator throws a
 * {@see ConstructionException}. Node-scoped methods (onTrue ... onExit, terminal, rejects,
 * response) apply to the node declared last with {@see self::node()}.
 */
final class ProcessDefinition
{
    public const DEFAULT_LOOP_LIMIT = 100;

    private bool $built = false;
    private ?string $contextClass = null;
    private bool $transactional = true;
    private ?string $validatorClass = null;
    private ?string $actorClass = null;
    /** @var list<string> */
    private array $requires = [];
    private int $loopLimit = self::DEFAULT_LOOP_LIMIT;
    private ?string $currentNode = null;
    /** @var array<string, RuleMode> */
    private array $ruleModes = [];
    /** @var array<string, list<array{status: ResponseStatus, messageKey: string}>> */
    private array $rejects = [];
    /** @var array<string, list<string>> */
    private array $responses = [];
    private WorkflowConfigBuilder $configBuilder;
    private ?WorkflowConfig $config = null;

    private function __construct(private readonly string $inputClass, private readonly string $name)
    {
        $this->configBuilder = new WorkflowConfigBuilder();
    }

    /**
     * @throws ConstructionException on an empty input class or name
     */
    public static function for(string $inputClass, string $name): self
    {
        if ($inputClass === '' || $name === '') {
            throw new ConstructionException('A process needs a non-empty input class and name');
        }

        return new self($inputClass, $name);
    }

    public function context(string $contextClass): self
    {
        $this->assertOpen();
        $this->contextClass = $contextClass;

        return $this;
    }

    public function transactional(bool $transactional): self
    {
        $this->assertOpen();
        $this->transactional = $transactional;

        return $this;
    }

    public function validate(string $validatorClass): self
    {
        $this->assertOpen();
        $this->validatorClass = $validatorClass;

        return $this;
    }

    public function actor(string $actorClass): self
    {
        $this->assertOpen();
        $this->actorClass = $actorClass;

        return $this;
    }

    public function requires(string ...$requires): self
    {
        $this->assertOpen();
        $this->requires = array_values(array_unique([...$this->requires, ...$requires]));

        return $this;
    }

    /**
     * @throws ConstructionException when the limit is below 1
     */
    public function loopLimit(int $loopLimit): self
    {
        $this->assertOpen();
        if ($loopLimit < 1) {
            throw new ConstructionException(sprintf('Loop limit must be at least 1, got %d', $loopLimit));
        }
        $this->loopLimit = $loopLimit;

        return $this;
    }

    /** The target node is checked at build(), it may be declared later. */
    public function ruleMode(string $node, RuleMode $mode): self
    {
        $this->assertOpen();
        $this->ruleModes[$node] = $mode;

        return $this;
    }

    /** Declares a node and makes it the current node; the first declared node is the entry node. */
    public function node(string $node): self
    {
        $this->assertOpen();
        $this->configBuilder->addNode($node);
        $this->currentNode = $node;

        return $this;
    }

    public function onTrue(?string $target): self
    {
        return $this->routeCurrent(Route::OnTrue, $target);
    }

    public function onFalse(?string $target): self
    {
        return $this->routeCurrent(Route::OnFalse, $target);
    }

    public function onSkip(?string $target): self
    {
        return $this->routeCurrent(Route::OnSkip, $target);
    }

    public function onTimeout(?string $target): self
    {
        return $this->routeCurrent(Route::OnTimeout, $target);
    }

    public function onCancel(?string $target): self
    {
        return $this->routeCurrent(Route::OnCancel, $target);
    }

    public function onExit(?string $target): self
    {
        return $this->routeCurrent(Route::OnExit, $target);
    }

    /**
     * Declares the given routes of the current node as a terminal end (default: {@see Route::OnTrue}).
     */
    public function terminal(Route ...$routes): self
    {
        foreach ($routes === [] ? [Route::OnTrue] : $routes as $route) {
            $this->routeCurrent($route, null);
        }

        return $this;
    }

    /**
     * Declares a 4xx the current node may reject with.
     *
     * @throws ConstructionException when the status is not 4xx
     */
    public function rejects(ResponseStatus $status, string $messageKey): self
    {
        $node = $this->requireCurrentNode();
        if ($status->value < 400 || $status->value > 499) {
            throw new ConstructionException(sprintf(
                'rejects() needs a 4xx status, got %d (%s)',
                $status->value,
                $status->name
            ));
        }
        $this->rejects[$node][] = ['status' => $status, 'messageKey' => $messageKey];

        return $this;
    }

    /** Restricts the success answer of the current node to the given fields. */
    public function response(string ...$fields): self
    {
        $node = $this->requireCurrentNode();
        $this->responses[$node] = array_values($fields);

        return $this;
    }

    /**
     * Validates the graph and freezes the definition.
     *
     * @throws ConstructionException on an empty graph, an unknown route target or an unknown rule-mode node
     */
    public function build(): self
    {
        if ($this->built) {
            return $this;
        }

        foreach (array_keys($this->ruleModes) as $node) {
            if (!$this->configBuilder->hasNode($node)) {
                throw new ConstructionException(sprintf('ruleMode() names unknown node "%s"', $node));
            }
        }

        $this->config = $this->configBuilder->build();
        $this->built = true;

        return $this;
    }

    public function isBuilt(): bool
    {
        return $this->built;
    }

    public function getInputClass(): string
    {
        return $this->inputClass;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getContextClass(): ?string
    {
        return $this->contextClass;
    }

    public function isTransactional(): bool
    {
        return $this->transactional;
    }

    public function getValidatorClass(): ?string
    {
        return $this->validatorClass;
    }

    public function getActorClass(): ?string
    {
        return $this->actorClass;
    }

    /**
     * @return list<string>
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    public function getLoopLimit(): int
    {
        return $this->loopLimit;
    }

    public function getRuleMode(string $node): ?RuleMode
    {
        return $this->ruleModes[$node] ?? null;
    }

    /**
     * @return list<array{status: ResponseStatus, messageKey: string}>
     */
    public function getRejects(string $node): array
    {
        return $this->rejects[$node] ?? [];
    }

    /**
     * @return list<string>|null null when the node declares no response restriction
     */
    public function getResponse(string $node): ?array
    {
        return $this->responses[$node] ?? null;
    }

    /**
     * The routing graph for the engine.
     *
     * @internal
     * @throws ConstructionException before build()
     */
    public function config(): WorkflowConfig
    {
        return $this->config ?? throw new ConstructionException('The definition is not built yet');
    }

    private function routeCurrent(Route $route, ?string $target): self
    {
        $this->assertOpen();
        $this->configBuilder->route($this->requireCurrentNode(), $route, $target);

        return $this;
    }

    private function requireCurrentNode(): string
    {
        $this->assertOpen();

        return $this->currentNode ?? throw new ConstructionException('Declare a node() first');
    }

    private function assertOpen(): void
    {
        if ($this->built) {
            throw new ConstructionException(sprintf(
                'Process "%s" is built and cannot be changed',
                $this->name
            ));
        }
    }
}
