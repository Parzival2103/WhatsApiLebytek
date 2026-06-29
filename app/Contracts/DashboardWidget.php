<?php

namespace App\Contracts;

interface DashboardWidget
{
    public function key(): string;

    public function permission(): string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;

    public function component(): ?string;
}
