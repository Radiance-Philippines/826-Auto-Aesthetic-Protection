<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Users\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Support\Access;
use App\Support\Impersonation;
use App\Support\Invitations;
use App\Support\Panels;
use App\Support\TeamAccounts;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The React admin's Users: super admins only (UserResource's own rules, which
 * ConsoleScreen applies to every route here, closed while impersonating).
 */
class ConsoleUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $users = User::query()->with('branches')->withCount('passkeys')->withMax('impersonationEvents', 'created_at')->orderBy('name')->get();
        $areas = TeamAccounts::areaOptions();

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'roleLabel' => $user->roleLabel(),
                'branchNames' => $user->isOwner() ? 'Every branch' : ($user->branches->pluck('name')->implode(', ') ?: 'No branch yet'),
                'branchIds' => $user->branches->pluck('id')->all(),
                'ownList' => is_array($user->permissions),
                'access' => array_values(array_intersect(array_keys($areas), $user->areas())),
                'sample' => $user->isSample(),
                'twoFactor' => $user->hasTwoFactor(),
                'passkeys' => (int) $user->passkeys_count,
                'invited' => $user->isPendingInvite() ? $user->invited_at->diffForHumans() : null,
                'lastViewedAs' => $user->impersonation_events_max_created_at ? Carbon::parse($user->impersonation_events_max_created_at)->diffForHumans() : 'Never',
                'isSelf' => $user->is($admin),
                'canViewAs' => Impersonation::refusal($admin, $user) === null,
                'canDelete' => TeamAccounts::canDelete($admin, $user),
            ])->all(),
            'options' => [
                'roles' => collect(Access::ROLES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
                'areas' => collect($areas)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
                'defaults' => collect(array_keys(Access::ROLES))->mapWithKeys(fn (string $role) => [$role => array_values(array_intersect(array_keys($areas), Access::defaults($role)))])->all(),
                'branches' => Branch::query()->ordered()->get(['id', 'name'])->map(fn (Branch $branch) => ['value' => (string) $branch->id, 'label' => $branch->name])->all(),
                'newRole' => Access::BRANCH_MANAGER,
                'superAdmin' => Access::SUPER_ADMIN,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, Invitations $invitations): JsonResponse
    {
        // As Invitations stores it, so the "already used" check sees the same address
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in(array_keys(Access::ROLES))],
            'branches' => [Rule::requiredIf($request->input('role') !== Access::SUPER_ADMIN), 'array'],
            'branches.*' => ['integer', Rule::exists(Branch::class, 'id')],
        ], ['branches.required' => 'Pick at least one branch.']);

        try {
            $user = $invitations->invite($data['name'], $data['email'], $request->user(), $data['role']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->branches()->sync($data['role'] === Access::SUPER_ADMIN ? [] : array_map('intval', $data['branches'] ?? []));

        return response()->json(['message' => "Invite sent to {$user->email}"], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless(UserResource::canEdit($user), 403);

        // You can't demote yourself: the form shows the role, and what it sends is ignored
        $self = $user->is($request->user());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class, 'email')->ignore($user)],
            'role' => [Rule::requiredIf(! $self), Rule::in(array_keys(Access::ROLES))],
            'access' => ['array'],
            'access.*' => ['string', Rule::in(array_keys(TeamAccounts::areaOptions()))],
            'branches' => ['array'],
            'branches.*' => ['integer', Rule::exists(Branch::class, 'id')],
        ]);
        $role = $self ? $user->role : $data['role'];

        if (TeamAccounts::wouldRemoveLastSuperAdmin($user, $role)) {
            throw ValidationException::withMessages(['role' => 'There must be at least one super admin.']);
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email'], 'role' => $role, 'permissions' => TeamAccounts::ownPermissions($role, $data['access'] ?? [])])->save();

        // A super admin sees every branch, so their ticks are left as they are
        if ($role !== Access::SUPER_ADMIN && array_key_exists('branches', $data)) {
            $user->branches()->sync(array_map('intval', $data['branches']));
        }

        return response()->json(['message' => 'Saved']);
    }

    public function destroy(Request $request, int $id): Response
    {
        $user = User::query()->findOrFail($id);
        abort_unless(TeamAccounts::canDelete($request->user(), $user), 403, 'This account can’t be deleted.');

        $user->delete();

        return response()->noContent();
    }

    public function resendInvite(Request $request, Invitations $invitations, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless($user->isPendingInvite(), 403, 'This person has already accepted their invite.');

        try {
            $invitations->send($user, $request->user());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "New invite sent to {$user->email}"]);
    }

    public function resetTwoFactor(int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless($user->hasTwoFactor(), 403, 'This person has no 2FA to reset.');

        $user->resetTwoFactor();

        return response()->json(['message' => "2FA reset for {$user->name}"]);
    }

    public function removePasskeys(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        abort_unless($user->passkeys()->exists(), 403, 'This person has no passkeys.');

        TeamAccounts::removePasskeys($user, $request->user());

        return response()->json(['message' => "Passkeys removed for {$user->name}"]);
    }

    /**
     * Answers where to go next; the browser loads it as a new page, with the
     * new session's CSRF token.
     */
    public function viewAs(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        $user = User::query()->findOrFail($id);

        if ($reason = Impersonation::refusal($admin, $user)) {
            return response()->json(['message' => $reason], 422);
        }

        Impersonation::start($admin, $user, Filament::getCurrentOrDefaultPanel()->getId());

        return response()->json(['url' => route('console.page', ['panel' => Panels::homeId($user)])]);
    }
}
