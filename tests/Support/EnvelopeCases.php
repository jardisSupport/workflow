<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;
use stdClass;

/**
 * The five envelope cases (success, 400, 404, 409, 422) as plain data, plus the tree builder.
 *
 * Shared by the tests and by the one-off script that recorded the fixtures from the pre-move
 * generated classes. It names no framework class: the context-response class is passed in.
 */
final class EnvelopeCases
{
    /** The frozen instant of the test clock; the pre-move code had no clock seam. */
    public const FIXED_AT = '2026-01-15 10:30:00';

    /** Same instant as `meta.timestamp` (format `c`, UTC). */
    public const FIXED_TIMESTAMP = '2026-01-15T10:30:00+00:00';

    /**
     * Case name => {status, version, tree}. `status` null = resolved from errors.
     * A tree is {context, data?, form?, errors?, events?, results?}; `form` = {kind, args}
     * names the failure payload, `data` is literal data.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'success' => [
                'status' => null,
                'version' => 'v1',
                'tree' => [
                    'context' => 'order',
                    'data' => [
                        '@type' => 'order',
                        'orderNumber' => 'ORD-2026-0001',
                        'total' => 129.9,
                        'paid' => false,
                        'lines' => [
                            ['sku' => 'A-1', 'qty' => 2],
                            ['sku' => 'B-7', 'qty' => 1],
                        ],
                        'note' => 'Größe 42 / blau',
                        'discount' => null,
                    ],
                    'events' => [
                        ['scope' => 'internal', 'event' => ['name' => 'OrderPlaced', 'orderNumber' => 'ORD-2026-0001']],
                    ],
                    'results' => [
                        [
                            'context' => 'inventory',
                            'events' => [
                                ['scope' => 'internal', 'event' => ['name' => 'StockReserved', 'sku' => 'A-1']],
                                ['scope' => 'domain', 'event' => ['name' => 'StockLow', 'sku' => 'B-7']],
                            ],
                            'results' => [
                                ['context' => 'warehouse'],
                            ],
                        ],
                    ],
                ],
            ],
            'validation' => [
                'status' => null,
                'version' => '',
                'tree' => [
                    'context' => 'order',
                    'form' => [
                        'kind' => 'validation',
                        'args' => [
                            'fields' => [
                                [
                                    'field' => 'email',
                                    'reason' => 'missing',
                                    'message' => 'Required',
                                    'messageKey' => 'order.email.missing',
                                ],
                                [
                                    'field' => 'items[1].qty',
                                    'reason' => 'min',
                                    'message' => 'Must be at least 1',
                                    'messageKey' => 'order.qty.min',
                                ],
                            ],
                        ],
                    ],
                    'errors' => ['email is required'],
                    'results' => [
                        ['context' => 'validator', 'errors' => ['items[1].qty must be at least 1']],
                    ],
                ],
            ],
            'notFound' => [
                'status' => 404,
                'version' => '',
                'tree' => [
                    'context' => 'order',
                    'form' => ['kind' => 'notFound', 'args' => ['context' => ['orderNumber' => 'ORD-404']]],
                ],
            ],
            'conflict' => [
                'status' => 409,
                'version' => '',
                'tree' => [
                    'context' => 'order',
                    'form' => [
                        'kind' => 'concurrencyConflict',
                        'args' => [
                            'reason' => 'stale',
                            'aggregate' => 'order',
                            'context' => ['orderNumber' => 'ORD-2026-0001', 'expectedVersion' => 3],
                            'violated' => ['uq_order_number'],
                        ],
                    ],
                ],
            ],
            'ruleViolation' => [
                'status' => 422,
                'version' => 'v2',
                'tree' => [
                    'context' => 'order',
                    'form' => [
                        'kind' => 'ruleViolation',
                        'args' => ['rule' => 'OrderMustBeOpen', 'messageKey' => 'order.rule.open', 'context' => []],
                    ],
                ],
            ],
        ];
    }

    /**
     * Builds a context-response tree from a definition tree.
     *
     * @param class-string<ContextResponseInterface> $contextResponseClass
     * @param array<string, mixed> $tree
     * @param array<string, mixed>|null $formData resolved data of `form` (null: not used by this node)
     */
    public static function build(string $contextResponseClass, array $tree, ?array $formData = null): ContextResponseInterface
    {
        /** @var ContextResponseInterface&object $node */
        $node = new $contextResponseClass($tree['context']);

        $data = $tree['data'] ?? $formData;
        if ($data !== null) {
            $node->setData($data);
        }
        foreach ($tree['errors'] ?? [] as $error) {
            $node->addError($error);
        }
        foreach ($tree['events'] ?? [] as $entry) {
            $node->addEvent((object) $entry['event'], EventScope::from($entry['scope']));
        }
        foreach ($tree['results'] ?? [] as $child) {
            $node->addResult(self::build($contextResponseClass, $child));
        }

        return $node;
    }
}
