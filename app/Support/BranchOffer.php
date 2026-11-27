<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which services each branch offers, as a branch manager sets it: only the
 * ticks. Names, prices and details stay with the super admin.
 */
final class BranchOffer
{
    /**
     * The branches in view: the chosen one, else all of theirs.
     *
     * @return Collection<int, Branch>
     */
    public static function branches(?User $user): Collection
    {
        return Branch::query()->visibleTo($user)->with('services:id')->ordered()->get();
    }

    /**
     * The services that can be ticked.
     *
     * @return Collection<string, Collection<int, Service>> by category name
     */
    public static function services(): Collection
    {
        return Service::query()->where('is_active', true)->with('category')->orderBy('sort_order')->orderBy('name')->get()
            ->groupBy(fn (Service $service) => $service->category?->name ?? 'Other');
    }

    /**
     * @param  array<int|string>  $serviceIds
     */
    public static function save(Branch $branch, array $serviceIds): void
    {
        // Hidden services aren't on the page, so their link to the branch stays as it was
        $hidden = $branch->services()->where('is_active', false)->pluck('services.id');

        $branch->services()->sync(Service::query()->whereKey($serviceIds)->pluck('id')->merge($hidden));
    }
}
