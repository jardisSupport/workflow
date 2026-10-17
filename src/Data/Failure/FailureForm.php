<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Data\Failure;

use JardisSupport\Contract\Kernel\ResponseStatus;

/**
 * Marker of every failure answer form; carries only the data every form shares.
 */
interface FailureForm
{
    public function status(): ResponseStatus;

    public function messageKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function context(): array;
}
