<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Support\Process\Order;

use JardisSupport\Contract\Validation\ValidatorInterface;
use JardisSupport\Validation\CompositeFieldValidator;
use JardisSupport\Validation\ObjectValidator;
use JardisSupport\Validation\Validator\NotBlank;
use JardisSupport\Validation\Validator\Range;
use JardisSupport\Validation\ValidatorRegistry;

/**
 * The real validator stack of the package under test: ObjectValidator with field paths.
 */
final class OrderValidation
{
    public static function validator(): ValidatorInterface
    {
        $registry = (new ValidatorRegistry())
            ->register(OrderInput::class, (new CompositeFieldValidator())
                ->field('customer')->validates(NotBlank::class)->end())
            ->register(OrderLine::class, (new CompositeFieldValidator())
                ->field('qty')->validates(Range::class, Range::min(1))->end());

        return new ObjectValidator($registry, fieldPaths: true);
    }
}
