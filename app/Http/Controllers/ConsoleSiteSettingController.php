<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\ImagePipeline;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The React admin's Site settings, like the Filament page (settings area,
 * closed while viewing as someone). The model converts the pictures and
 * deletes the ones replaced. Only these fields are read or written: the same
 * row holds the Google secret.
 */
class ConsoleSiteSettingController extends Controller
{
    /** Filament's own rule for a phone number */
    private const PHONE = '/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';

    private const TEXT = ['tagline' => 'tagline', 'intro' => 'intro', 'phone' => 'phone', 'contactEmail' => 'contact_email', 'facebookUrl' => 'facebook_url', 'instagramUrl' => 'instagram_url', 'messengerUrl' => 'messenger_url'];

    private const PICTURES = ['heroImage' => 'hero_image', 'ogImage' => 'og_image'];

    public function show(): JsonResponse
    {
        return $this->record(SiteSetting::instance());
    }

    public function update(Request $request): JsonResponse
    {
        $link = ['nullable', 'string', 'url', 'starts_with:https://', 'max:255'];
        $rules = [
            'tagline' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:'.self::PHONE],
            'contactEmail' => ['nullable', 'email', 'max:190'],
            'facebookUrl' => $link,
            'instagramUrl' => $link,
            'messengerUrl' => $link,
        ];
        $messages = ['phone.regex' => 'Type a phone number.'];

        foreach (array_keys(self::PICTURES) as $picture) {
            $rules[$picture] = ['nullable', ...UploadRules::image(UploadRules::PHOTO_MAX_KB)];
            $rules['remove'.ucfirst($picture)] = ['boolean'];
            $messages += UploadRules::messages($picture, UploadRules::PHOTO_MAX_KB);
        }

        $data = $request->validate($rules, $messages, ['contactEmail' => 'contact email', 'facebookUrl' => 'Facebook page', 'instagramUrl' => 'Instagram', 'messengerUrl' => 'Messenger link']);
        $fields = [];

        foreach (self::TEXT as $name => $column) {
            if (array_key_exists($name, $data)) {
                $fields[$column] = $data[$name];
            }
        }

        foreach (self::PICTURES as $name => $column) {
            if ($file = $request->file($name)) {
                $fields[$column] = $file->store('site', 'public');
            } elseif ($request->boolean('remove'.ucfirst($name))) {
                $fields[$column] = null;
            }
        }

        $settings = SiteSetting::instance();
        $settings->update($fields);

        return $this->record($settings);
    }

    private function record(SiteSetting $settings): JsonResponse
    {
        $pipeline = app(ImagePipeline::class);

        return response()->json([
            ...collect(self::TEXT)->map(fn (string $column) => $settings->{$column})->all(),
            'heroImageUrl' => $settings->hero_image ? $pipeline->url($settings->hero_image, 'md') : null,
            'ogImageUrl' => $settings->og_image ? $pipeline->url($settings->og_image, 'md') : null,
        ])->header('Cache-Control', 'no-store');
    }
}
