<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

/**
 * Reads a list of backing values as a list of enum cases; null stays null.
 *
 * Decorates {@see ReadEnum}: every element is read the same way, an invalid element raises the
 * {@see \ValueError} of `BackedEnum::from()` unchanged.
 */
final class ReadEnumList
{
    public function __construct(private readonly ReadEnum $readEnum = new ReadEnum())
    {
    }

    /**
     * @param array<array-key, int|string|null>|null $values
     * @param class-string<\BackedEnum> $enumClass
     * @return list<\BackedEnum|null>|null
     * @throws \ValueError when an element is no case of the enum
     */
    public function __invoke(?array $values, string $enumClass): ?array
    {
        if ($values === null) {
            return null;
        }

        $cases = [];
        foreach ($values as $value) {
            $cases[] = ($this->readEnum)($value, $enumClass);
        }

        return $cases;
    }
}
