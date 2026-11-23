<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * For a Livewire page that shows what Radiance Flow announces (resources/js/admin-live.ts): when its area changes,
 * the page looks again, with no reload. Pages say which area, and which branch they are showing if they show one.
 */
trait RefreshesLive
{
    /**
     * queue, bookings or inbox
     */
    abstract protected function liveArea(): string;

    /**
     * The branch the page is showing, or null when it shows every branch.
     */
    protected function liveBranch(): ?int
    {
        return null;
    }

    /**
     * Anything the page must redo when it looks again.
     */
    protected function liveRefreshed(): void {}

    /**
     * @param  array<string, int|null>  $areas  what changed (area => branch, or null for any branch)
     */
    #[On('flow-live')]
    public function refreshLive(array $areas = []): void
    {
        $area = $this->liveArea();

        // Rendering again is the refresh: the page reads its data afresh each time
        if (! array_key_exists($area, $areas) || ($areas[$area] !== null && $this->liveBranch() !== null && (int) $areas[$area] !== $this->liveBranch())) {
            $this->skipRender();

            return;
        }

        $this->liveRefreshed();
    }
}
