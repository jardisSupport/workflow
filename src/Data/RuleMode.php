<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * How a rule node participates in a process: as a guard (rejects the run) or as a decision (routes it).
 */
enum RuleMode: string
{
    case Guard = 'guard';
    case Decision = 'decision';
}
