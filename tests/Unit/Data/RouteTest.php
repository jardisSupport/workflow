<?php

declare(strict_types=1);

namespace JardisSupport\Workflow\Tests\Unit\Data;

use JardisSupport\Workflow\Data\Route;
use PHPUnit\Framework\TestCase;

/**
 * Pure logic of the Route enum: six cases, string value = lcfirst case name.
 */
class RouteTest extends TestCase
{
    public function testHasExactlySixCases(): void
    {
        $this->assertSame(
            ['OnTrue', 'OnFalse', 'OnSkip', 'OnTimeout', 'OnCancel', 'OnExit'],
            array_map(static fn (Route $r): string => $r->name, Route::cases())
        );
    }

    public function testValueIsLcfirstCaseName(): void
    {
        foreach (Route::cases() as $route) {
            $this->assertSame(lcfirst($route->name), $route->value);
        }
    }

    public function testFromResolvesTheStringValue(): void
    {
        $this->assertSame(Route::OnExit, Route::from('onExit'));
        $this->assertNull(Route::tryFrom('onSuccess'));
    }
}
