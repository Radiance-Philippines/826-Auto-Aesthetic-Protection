<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * A branch manager picks which services their branch offers. Names, prices
 * and details stay with the super admin (Shop → Services).
 */
class BranchServices extends Page
{
    public const AREA = 'branch.services';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $title = 'Services';

    protected static ?string $slug = 'branch-services';

    protected string $view = 'filament.pages.branch-services';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canAccessArea(self::AREA) && ($user->isOwner() || $user->branchIds() !== []);
    }

    /**
     * The branches in view: the chosen one, else all of theirs.
     *
     * @return Collection<int, Branch>
     */
    public function branches(): Collection
    {
        return Branch::query()->visibleTo(auth()->user())->with('services:id')->ordered()->get();
    }

    /**
     * @return Collection<string, Collection<int, Service>> by category name
     */
    public function services(): Collection
    {
        return Service::query()->where('is_active', true)->with('category')->orderBy('sort_order')->orderBy('name')->get()
            ->groupBy(fn (Service $service) => $service->category?->name ?? 'Other');
    }
}
