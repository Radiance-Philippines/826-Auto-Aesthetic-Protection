<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The React admin's App releases, under AppReleasePolicy (super admin only)
 * like the Filament resource. The APK goes to the private disk; its size and
 * sha256 come from the stored file.
 */
class ConsoleAppReleaseController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AppRelease::class);

        return response()->json([
            'data' => AppRelease::query()->orderByDesc('version_code')->orderByDesc('id')->get()->map(fn (AppRelease $release) => $this->row($release))->all(),
            'apps' => collect(AppRelease::apps())->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', AppRelease::class);

        $release = AppRelease::query()->create($this->validated($request, null));

        return response()->json($this->row($release), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $release = AppRelease::query()->findOrFail($id);
        Gate::authorize('update', $release);

        $release->update($this->validated($request, $release));

        return response()->json($this->row($release));
    }

    /**
     * The row goes; the file stays on the private disk, as in the Filament admin.
     */
    public function destroy(int $id): Response
    {
        $release = AppRelease::query()->findOrFail($id);
        Gate::authorize('delete', $release);

        $release->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AppRelease $release): array
    {
        $app = (string) ($request->input('app') ?: AppRelease::CUSTOMER);
        $unique = fn (string $column) => Rule::unique(AppRelease::class, $column)->where('app', $app)->ignore($release);

        $data = $request->validate([
            'app' => ['required', Rule::in(array_keys(AppRelease::apps()))],
            'version' => ['required', 'string', 'max:20', 'regex:'.AppRelease::VERSION_PATTERN, $unique('version')],
            'versionCode' => ['required', 'integer', 'min:1', $unique('version_code')],
            'apk' => [$release ? 'nullable' : 'required', 'file', 'mimetypes:'.implode(',', AppRelease::APK_TYPES), 'max:'.AppRelease::APK_MAX_KB],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mandatory' => ['required', 'boolean'],
            'isPublished' => ['required', 'boolean'],
        ], [
            'version.regex' => 'Write the version like 0.1.0.',
            'version.unique' => 'This app already has that version.',
            'versionCode.unique' => 'This app already has that version code.',
            'apk.required' => 'Choose the APK file.',
            'apk.mimetypes' => 'Choose an Android APK file.',
            'apk.max' => 'Keep the APK under 300 MB.',
            // PHP refused it before Laravel saw it (upload_max_filesize)
            'apk.uploaded' => 'That file is too big for the server. Try a smaller one.',
        ], ['versionCode' => 'version code']);

        $fields = [
            'app' => $data['app'],
            'version' => $data['version'],
            'version_code' => (int) $data['versionCode'],
            'notes' => $data['notes'] ?? null,
            'mandatory' => (bool) $data['mandatory'],
            'is_published' => (bool) $data['isPublished'],
        ];

        if ($file = $request->file('apk')) {
            $fields['apk_path'] = $file->storeAs('apks', Str::ulid().'.apk', AppRelease::DISK);
        }

        return AppRelease::fingerprint($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AppRelease $release): array
    {
        return [
            'id' => $release->getKey(),
            'app' => $release->app,
            'appLabel' => AppRelease::apps()[$release->app] ?? $release->app,
            'version' => $release->version,
            'versionCode' => $release->version_code,
            'size' => (int) $release->size,
            'sizeLabel' => number_format((int) $release->size / 1048576, 1).' MB',
            'notes' => $release->notes,
            'mandatory' => (bool) $release->mandatory,
            'isPublished' => (bool) $release->is_published,
            'added' => $release->created_at?->format('M j, Y'),
        ];
    }
}
