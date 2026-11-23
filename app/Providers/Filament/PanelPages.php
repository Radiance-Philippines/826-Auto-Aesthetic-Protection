<?php

namespace App\Providers\Filament;

use App\Filament\Pages\BookingSettings;
use App\Filament\Pages\EmailSettings;
use App\Filament\Pages\GoogleSignInSettings;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\ManualTransactionPage;
use App\Filament\Pages\MetaSettings;
use App\Filament\Pages\PaymentSettings;
use App\Filament\Pages\Queue;
use App\Filament\Pages\ReferralSettings;
use App\Filament\Pages\SecuritySettings;
use App\Filament\Pages\SiteSettings;
use App\Filament\Pages\Warranty;
use App\Filament\Resources\AppReleases\AppReleaseResource;
use App\Filament\Resources\BackjobTypes\BackjobTypeResource;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\BranchBlocks\BranchBlockResource;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Photos\PhotoResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VehicleSizes\VehicleSizeResource;
use App\Filament\Resources\Videos\VideoResource;
use App\Filament\Widgets\BookingStats;
use App\Filament\Widgets\BranchesInsight;
use App\Filament\Widgets\CustomersInsight;
use App\Filament\Widgets\NeedsAttention;
use App\Filament\Widgets\OperationsInsight;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\SalesOverview;
use App\Filament\Widgets\ServicesInsight;
use App\Filament\Widgets\StaffInsight;

/**
 * What each panel registers, spelled out. Every panel has the booking work
 * (the pages link to one another, so they are always there); a person only
 * sees the ones their areas allow. The shop, site, settings, Users and App
 * releases pages are registered everywhere too, only so that an account given
 * one of those areas by hand can still open it (Users and App releases stay
 * super admin only, by their policies): they stay hidden for everyone else,
 * and a refused account gets the same 403 in any panel.
 */
final class PanelPages
{
    /**
     * @return list<class-string>
     */
    public static function work(): array
    {
        return [
            BookingResource::class,
            CustomerResource::class,
            BranchBlockResource::class,
            ReviewResource::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public static function workPages(): array
    {
        return [Queue::class, Warranty::class, Inbox::class, ManualTransactionPage::class];
    }

    /**
     * @return list<class-string>
     */
    public static function shopAndSite(): array
    {
        return [
            BranchResource::class,
            ServiceResource::class,
            ServiceCategoryResource::class,
            VehicleSizeResource::class,
            BackjobTypeResource::class,
            PhotoResource::class,
            VideoResource::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public static function settingsPages(): array
    {
        return [
            SiteSettings::class,
            BookingSettings::class,
            PaymentSettings::class,
            EmailSettings::class,
            GoogleSignInSettings::class,
            MetaSettings::class,
            ReferralSettings::class,
            SecuritySettings::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    public static function systemResources(): array
    {
        return [UserResource::class, AppReleaseResource::class];
    }

    /**
     * Today at the branches, then the sales side and the staff.
     *
     * @return list<class-string>
     */
    public static function ownerWidgets(): array
    {
        return [BookingStats::class, NeedsAttention::class, SalesOverview::class, SalesChart::class, ServicesInsight::class, BranchesInsight::class, OperationsInsight::class, CustomersInsight::class, StaffInsight::class];
    }

    /**
     * @return list<class-string>
     */
    public static function salesWidgets(): array
    {
        return [BookingStats::class, NeedsAttention::class, SalesOverview::class, SalesChart::class, ServicesInsight::class, CustomersInsight::class];
    }

    /**
     * @return list<class-string>
     */
    public static function operationsWidgets(): array
    {
        return [BookingStats::class, NeedsAttention::class, OperationsInsight::class, CustomersInsight::class];
    }
}
