<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Context;

/**
 * Reads a backing value as its enum case; null stays null.
 *
 * A value that is no case of the enum raises the {@see \ValueError} of `BackedEnum::from()` unchanged.
 */
final class ReadEnum
{
    /**
     * @param class-string<\BackedEnum> $enumClass
     * @throws \ValueError when the value is no case of the enum
     */
    public function __invoke(int|string|null $value, string $enumClass): ?\BackedEnum
    {
        return $value === null ? null : $enumClass::from($value);
    }
}
