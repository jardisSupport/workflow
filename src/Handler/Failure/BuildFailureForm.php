<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;
use JardisSupport\Workflow\Data\Failure\FailureForm;

/**
 * The one mapping from a reject (status, message key, context, errors) to its failure form.
 *
 * 400, 404, 409 and 422 have a form of their own, every other status has none (null). The form
 * is built from the context keys it knows - 400 `fields`, 409 `reason`/`aggregate`/`violated`,
 * 422 `rule` - the remaining keys travel as the context of the form. A 400 without `fields`
 * reports each error text as one finding without a field name, so no message gets lost.
 */
final class BuildFailureForm
{
    public function __construct(
        private readonly ValidationPayload $validation = new ValidationPayload(),
        private readonly NotFoundPayload $notFound = new NotFoundPayload(),
        private readonly ConcurrencyConflictPayload $conflict = new ConcurrencyConflictPayload(),
        private readonly RuleViolationPayload $ruleViolation = new RuleViolationPayload(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $errors
     */
    public function __invoke(ResponseStatus $status, string $messageKey, array $context, array $errors): ?FailureForm
    {
        return match ($status) {
            ResponseStatus::ValidationError => ($this->validation)(
                $messageKey,
                $this->fields($context, $errors),
                array_diff_key($context, ['fields' => true])
            ),
            ResponseStatus::NotFound => ($this->notFound)($messageKey, $context),
            ResponseStatus::Conflict => ($this->conflict)(
                $messageKey,
                $this->text($context, 'reason'),
                $this->text($context, 'aggregate'),
                array_diff_key($context, ['reason' => true, 'aggregate' => true, 'violated' => true]),
                $this->texts($context['violated'] ?? [])
            ),
            ResponseStatus::RuleViolation => ($this->ruleViolation)(
                $this->text($context, 'rule'),
                $messageKey,
                array_diff_key($context, ['rule' => true])
            ),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $errors
     * @return list<array{field: string, reason: string, message: string, messageKey?: string}>
     */
    private function fields(array $context, array $errors): array
    {
        $declared = $context['fields'] ?? null;
        if (is_array($declared)) {
            /** @var list<array{field: string, reason: string, message: string, messageKey?: string}> $declared */
            return $declared;
        }

        return array_map(
            static fn(string $message): array => ['field' => '', 'reason' => 'invalid', 'message' => $message],
            $errors
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function text(array $context, string $key): string
    {
        $value = $context[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private function texts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $texts = array_map(static fn(mixed $item): string => is_scalar($item) ? (string) $item : '', $value);

        return array_values($texts);
    }
}
