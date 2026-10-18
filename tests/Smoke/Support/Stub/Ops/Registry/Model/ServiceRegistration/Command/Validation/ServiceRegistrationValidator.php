<?php

declare(strict_types=1);

namespace Ops\Registry\Model\ServiceRegistration\Command\Validation;

use JardisSupport\Validation\CompositeFieldValidator;
use JardisSupport\Validation\Validator\NotEmpty;

/**
 * Stub of the generated request validator of the ServiceRegistration command DTOs: the stub DTOs
 * carry the single field `name`.
 */
final class ServiceRegistrationValidator
{
    public function __invoke(): CompositeFieldValidator
    {
        $validator = new CompositeFieldValidator();
        $validator->field('name')->validates(NotEmpty::class, NotEmpty::trimmed());

        return $validator;
    }
}
