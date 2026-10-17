<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * The six named routes a node result can take; the string value is the transition key.
 */
enum Route: string
{
    case OnTrue = 'onTrue';
    case OnFalse = 'onFalse';
    case OnSkip = 'onSkip';
    case OnTimeout = 'onTimeout';
    case OnCancel = 'onCancel';
    case OnExit = 'onExit';
}
