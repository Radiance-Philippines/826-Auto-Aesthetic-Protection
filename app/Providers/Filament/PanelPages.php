<?php

namespace App\Providers\Filament;

use App\Filament\Pages\BookingSettings;
use App\Filament\Pages\BranchServices;
use App\Filament\Pages\Dashboard;
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
use App\Filament\Pages\SystemHealth;
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
use App\Support\Panels;

/**
 * What each panel registers, and what its sidebar shows. The three working
 * panels register the same pages (they link to one another, and an old link
 * must still find a route to redirect from), but each one shows and opens only
 * its own set (shown): a page outside it is redirected to the panel where it
 * lives (AuthenticatePanel), and a person only sees the entries their areas
 * allow. /system registers the same, with its health page for a dashboard.
 */
final class PanelPages
{
    /**
     * @return list<string>
     */
    public static function panels(): array
    {
        return [Panels::OWNER, Panels::SALES, Panels::OPERATIONS, Panels::SYSTEM];
    }

    /**
     * The pages and resources one panel shows in its sidebar and opens.
     *
     * @return list<class-string>
     */
    public static function shown(string $panelId): array
    {
        return match ($panelId) {
            Panels::OWNER => [
                Dashboard::class,
                BranchResource::class, ServiceResource::class, ServiceCategoryResource::class, VehicleSizeResource::class, BackjobTypeResource::class, BranchBlockResource::class,
                PhotoResource::class, VideoResource::class, ReviewResource::class,
                ReferralSettings::class, BookingSettings::class, PaymentSettings::class, SiteSettings::class,
                UserResource::class,
            ],
            Panels::SALES => [
                Dashboard::class,
                BookingResource::class, CustomerResource::class, Warranty::class, ReviewResource::class, Inbox::class, ManualTransactionPage::class, BranchBlockResource::class,
            ],
            Panels::OPERATIONS => [
                Dashboard::class, Queue::class,
                BookingResource::class, CustomerResource::class, Warranty::class, BranchBlockResource::class, Inbox::class, ReviewResource::class, ManualTransactionPage::class,
                BranchServices::class,
            ],
            Panels::SYSTEM => [
                SystemHealth::class, AppReleaseResource::class, MetaSettings::class, GoogleSignInSettings::class, EmailSettings::class, SecuritySettings::class,
            ],
            default => [],
        };
    }

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
        return [Queue::class, Warranty::class, Inbox::class, ManualTransactionPage::class, BranchServices::class];
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
