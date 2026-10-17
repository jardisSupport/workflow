<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Handler\Failure;

use JardisSupport\Contract\Validation\ValidationResult;

/**
 * Gives one field finding of the 400 answer its vocabulary: a `messageKey` and, when the
 * validator delivered no text, a standard text.
 *
 * The validator contract hands out `field`, `reason` and `message` and leaves the answer
 * vocabulary to the caller ({@see ValidationResult::toFields()}). This is that caller's one place:
 * the key is `validation.<reason>` for the two reasons a validator produces (`missing`, otherwise
 * `invalid`); the standard text is `Field is required.` for `missing`, `Field is invalid.` for
 * everything else. A text the validator did deliver stays untouched.
 */
final class DescribeFieldFinding
{
    public const KEY_PREFIX = 'validation';
    public const REASON_MISSING = ValidationResult::REASON_MISSING;
    public const REASON_INVALID = ValidationResult::REASON_INVALID;
    public const TEXT_MISSING = 'Field is required.';
    public const TEXT_INVALID = 'Field is invalid.';

    /**
     * @param array{field: string, reason: string, message: string} $finding
     * @return array{field: string, reason: string, message: string, messageKey: string}
     */
    public function __invoke(array $finding): array
    {
        $missing = $finding['reason'] === self::REASON_MISSING;
        $standardText = $missing ? self::TEXT_MISSING : self::TEXT_INVALID;

        return [
            'field' => $finding['field'],
            'reason' => $finding['reason'],
            'message' => $finding['message'] !== '' ? $finding['message'] : $standardText,
            'messageKey' => self::KEY_PREFIX . '.' . ($missing ? self::REASON_MISSING : self::REASON_INVALID),
        ];
    }
}
