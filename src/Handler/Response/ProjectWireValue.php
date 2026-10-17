<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Response;

use DateTimeInterface;
use JsonSerializable;
use UnitEnum;

/**
 * Projects one value of the response `data` onto its wire form.
 *
 * A {@see DateTimeInterface} becomes an RFC 3339 string ({@see DateTimeInterface::ATOM}, with its own
 * offset) - json_encode would turn it into `{date, timezone_type, timezone}`. Arrays are walked, and so
 * are the public fields of plain nested objects; an object that has a wire form of its own
 * (`JsonSerializable`, an enum) is left to json_encode. An object without a date below it comes back
 * as the very same instance.
 *
 * Atomic closure - one entry point.
 */
final class ProjectWireValue
{
    public function __invoke(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_array($value) => $this->walk($value),
            !is_object($value), $value instanceof JsonSerializable, $value instanceof UnitEnum => $value,
            default => $this->walkObject($value),
        };
    }

    /**
     * @param array<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private function walk(array $items): array
    {
        foreach ($items as $key => $item) {
            $items[$key] = $this($item);
        }

        return $items;
    }

    private function walkObject(object $object): object
    {
        $fields = get_object_vars($object);
        $projected = $this->walk($fields);

        return $projected === $fields ? $object : (object) $projected;
    }
}
