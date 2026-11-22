<?php

namespace App\Support;

/**
 * What each role can do in the admin. An area is one kind of work; a role
 * has a default list of them, and one account can be given its own list
 * (users.permissions) instead. Branch scoping is separate: everyone but a
 * super admin only works with the branches they are assigned to.
 */
final class Access
{
    public const SUPER_ADMIN = 'super_admin';

    public const BRANCH_MANAGER = 'branch_manager';

    public const OPERATIONS = 'operations';

    public const SALES_ADMIN = 'sales_admin';

    /**
     * @var array<string, string>
     */
    public const ROLES = [
        self::SUPER_ADMIN => 'Super admin',
        self::BRANCH_MANAGER => 'Branch manager',
        self::OPERATIONS => 'Operations (head detailer)',
        self::SALES_ADMIN => 'Sales admin',
    ];

    /**
     * @var array<string, string>
     */
    public const AREAS = [
        'bookings.view' => 'See bookings and the queue',
        'bookings.sales' => 'Sales on bookings: confirm, decline, payments, size and price, move, cancel, manual transactions',
        'queue.update' => 'Move units through the queue and change the ready date',
        'customers.view' => 'See customers',
        'customers.write' => 'Add and edit customers, redeem points',
        'warranty' => 'Warranty checker and backjobs',
        'reviews' => 'Approve and reject reviews',
        'closures' => 'Close days and mark them full at their branches',
        'content' => 'Photos and videos',
        'inbox' => 'Messenger inbox',
        'dashboard' => 'Dashboard',
        'shop' => 'Branches, services, categories, sizes, backjob types and closures for every branch',
        'settings' => 'Settings pages',
        'users' => 'Users',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const DEFAULTS = [
        self::BRANCH_MANAGER => ['bookings.view', 'bookings.sales', 'queue.update', 'customers.view', 'customers.write', 'warranty', 'reviews', 'closures', 'inbox', 'dashboard'],
        self::OPERATIONS => ['bookings.view', 'queue.update', 'customers.view', 'warranty', 'dashboard'],
        self::SALES_ADMIN => ['bookings.view', 'bookings.sales', 'customers.view', 'customers.write', 'warranty', 'reviews', 'inbox', 'dashboard'],
    ];

    /**
     * The areas a role has until an account is given its own list.
     *
     * @return list<string>
     */
    public static function defaults(?string $role): array
    {
        return $role === self::SUPER_ADMIN ? array_keys(self::AREAS) : (self::DEFAULTS[$role ?? self::BRANCH_MANAGER] ?? []);
    }
}
