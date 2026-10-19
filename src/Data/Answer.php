<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data;

/**
 * The form of the answer of a successful run, declared per process with {@see ProcessDefinition::answer()}.
 *
 * - LastNode: the data of the last executed step; a last step without data answers an empty object (default).
 * - Merged: the data of all executed steps united in run order; on the same field name the later step wins.
 */
enum Answer: string
{
    case LastNode = 'lastNode';
    case Merged = 'merged';
}
