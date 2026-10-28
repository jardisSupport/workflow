<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Builder\WorkflowConfigBuilder;
use JardisSupport\Workflow\Exception\ConstructionException;

/**
 * Declarative description of one process: input, start contract, node graph and per-node declarations.
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
    /** @var list<string> */
    private array $requires = [];
    private int $loopLimit = self::DEFAULT_LOOP_LIMIT;
    private Answer $answer = Answer::LastNode;
    private ?string $answerResponseClass = null;
    /** @var list<list<string>>|null */
    private ?array $answerFrom = null;
    /** @var list<string> */
    private array $answerResponseFields = [];
    private ?string $currentNode = null;
    /** @var array<string, RuleMode> */
    private array $ruleModes = [];
    /** @var array<string, list<array{status: ResponseStatus, messageKey: string}>> */
    private array $rejects = [];
    /** @var array<string, string> */
    private array $inputs = [];
    /** @var array<string, list<string>> */
    private array $responses = [];
    /** @var array<string, string> */
    private array $responseClasses = [];
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

    /**
     * @deprecated Validation of the input is done by the builder (the door, before the run); the engine ignores it.
     */
    public function validate(string $validatorClass): self
    {
        $this->assertOpen();
        $this->validatorClass = $validatorClass;

        return $this;
    }

    /**
     * @deprecated The requirements are checked by the builder (the door, before the run); the engine ignores them.
     */
    public function requires(string ...$requires): self
    {
        $this->assertOpen();
        $this->requires = array_values(array_unique([...$this->requires, ...$requires]));

        return $this;
    }

    /**
     * Declares the form of the answer of a successful run; without it the answer is the data of the last step.
     *
     * @deprecated Declare the answer with {@see self::answerFrom()}: it names the nodes that give the answer.
     */
    public function answer(Answer $answer): self
    {
        $this->assertOpen();
        $this->answer = $answer;

        return $this;
    }

    /**
     * Declares the typed answer of the whole process: the response class and the subset of its constructor
     * fields taken from the united data of all steps (none named = all fields). The union is projected onto
     * these fields, so the answer does not depend on the kind or the data of the last node.
     *
     * Only for {@see Answer::Merged}; build() throws a {@see ConstructionException} for any other answer form,
     * wherever in the chain this method is called. Not to be mixed up with {@see self::response()}, which
     * types the answer of one node.
     *
     * @deprecated Declare the answer with {@see self::answerFrom()}; a typed callee answer is built by the node with
     *             {@see \JardisSupport\Workflow\Handler\Node\MapResponseInto}.
     * @throws ConstructionException on an empty class name
     */
    public function answerResponse(string $responseClass, string ...$fields): self
    {
        $this->assertOpen();
        if ($responseClass === '') {
            throw new ConstructionException(
                sprintf('answerResponse() of process "%s" needs a response class', $this->name)
            );
        }
        $this->answerResponseClass = $responseClass;
        $this->answerResponseFields = array_values($fields);

        return $this;
    }

    /**
     * Declares the answer of a successful run as named entries taken from the end of the run.
     *
     * Each entry is a list of node ids: the candidates that can give that entry. At the end of the run the
     * LAST executed step of any candidate gives the entry (several candidates, e.g. two nodes that both
     * give the same kind of answer, are allowed). The data of all entries is united; the package knows no field
     * name. A reject answers as before.
     *
     * Without Answer::Merged, without {@see self::answerResponse()} and without a node {@see self::response()}.
     * Calling it without an entry declares an empty answer.
     *
     * @param list<string> ...$entries
     */
    public function answerFrom(array ...$entries): self
    {
        $this->assertOpen();
        $this->answerFrom = array_values($entries);

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

    /**
     * Names the process input property that feeds the current node: a 400 reject of this node
     * answers its field paths below that property (`customer.email` for the finding `email`).
     *
     * @throws ConstructionException on an empty property name
     */
    public function input(string $property): self
    {
        $node = $this->requireCurrentNode();
        if ($property === '') {
            throw new ConstructionException('input() needs a non-empty property name');
        }
        $this->inputs[$node] = $property;

        return $this;
    }

    /**
     * Declares the typed answer of the current node: the response class and the subset of its
     * constructor fields taken from the node data (none named = all fields).
     *
     * @deprecated The node builds its typed answer itself with
     *             {@see \JardisSupport\Workflow\Handler\Node\MapResponseInto}.
     * @throws ConstructionException on an empty class name
     */
    public function response(string $responseClass, string ...$fields): self
    {
        $node = $this->requireCurrentNode();
        if ($responseClass === '') {
            throw new ConstructionException(sprintf('response() of node "%s" needs a response class', $node));
        }
        $this->responseClasses[$node] = $responseClass;
        $this->responses[$node] = array_values($fields);

        return $this;
    }

    /**
     * Validates the graph and freezes the definition.
     *
     * @throws ConstructionException on an empty graph, an unknown route target, an unknown rule-mode node, a
     *                               process answer class without Answer::Merged, or an invalid answerFrom()
     *                               (empty entry, unknown node, mixed with another answer declaration)
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

        $this->assertAnswerFrom();

        if ($this->answerResponseClass !== null && $this->answer !== Answer::Merged) {
            throw new ConstructionException(sprintf(
                'answerResponse() of process "%s" needs Answer::Merged, the answer is %s',
                $this->name,
                $this->answer->value
            ));
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

    /**
     * @deprecated Validation of the input is done by the builder (the door, before the run); the engine ignores it.
     */
    public function getValidatorClass(): ?string
    {
        return $this->validatorClass;
    }

    /**
     * @deprecated The requirements are checked by the builder (the door, before the run); the engine ignores them.
     *
     * @return list<string>
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * @deprecated Declare the answer with {@see self::answerFrom()}.
     */
    public function getAnswer(): Answer
    {
        return $this->answer;
    }

    /**
     * @return list<list<string>>|null the declared entries of the answer, each a list of candidate node ids
     *                                 (empty = an empty answer); null when answerFrom() was not declared
     */
    public function getAnswerFrom(): ?array
    {
        return $this->answerFrom;
    }

    /**
     * @deprecated Declare the answer with {@see self::answerFrom()}.
     *
     * @return string|null the declared answer class of the process; null when the process declares none
     */
    public function getAnswerResponseClass(): ?string
    {
        return $this->answerResponseClass;
    }

    /**
     * @deprecated Declare the answer with {@see self::answerFrom()}.
     *
     * @return list<string>|null the declared field subset of the process answer (empty = all fields); null when the
     *                           process declares no answer class
     */
    public function getAnswerResponse(): ?array
    {
        return $this->answerResponseClass === null ? null : $this->answerResponseFields;
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
     * @return string|null the input property that feeds the node; null when none is named
     */
    public function getInput(string $node): ?string
    {
        return $this->inputs[$node] ?? null;
    }

    /**
     * @deprecated The node builds its typed answer itself with
     *             {@see \JardisSupport\Workflow\Handler\Node\MapResponseInto}.
     *
     * @return list<string>|null the declared field subset (empty = all fields); null when the node
     *                           declares no response
     */
    public function getResponse(string $node): ?array
    {
        return $this->responses[$node] ?? null;
    }

    /**
     * @deprecated The node builds its typed answer itself with
     *             {@see \JardisSupport\Workflow\Handler\Node\MapResponseInto}.
     *
     * @return string|null the declared response class; null when the node declares none
     */
    public function getResponseClass(string $node): ?string
    {
        return $this->responseClasses[$node] ?? null;
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

    /**
     * @throws ConstructionException
     */
    private function assertAnswerFrom(): void
    {
        if ($this->answerFrom === null) {
            return;
        }
        foreach ($this->answerFrom as $position => $entry) {
            if ($entry === []) {
                throw new ConstructionException(sprintf(
                    'answerFrom() of process "%s": entry %d names no node',
                    $this->name,
                    $position + 1
                ));
            }
            foreach ($entry as $node) {
                if (!$this->configBuilder->hasNode($node)) {
                    throw new ConstructionException(sprintf(
                        'answerFrom() of process "%s" names unknown node "%s"',
                        $this->name,
                        $node
                    ));
                }
            }
        }
        if ($this->answer === Answer::Merged || $this->answerResponseClass !== null || $this->responseClasses !== []) {
            throw new ConstructionException(sprintf(
                'answerFrom() of process "%s" cannot be mixed with Answer::Merged, answerResponse() or response()',
                $this->name
            ));
        }
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
