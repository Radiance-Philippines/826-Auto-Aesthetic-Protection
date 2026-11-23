<?php

// Only the upload limit differs from Livewire's defaults: release APKs (~70 MB) go through the
// Filament App releases form, and Livewire's default temporary upload cap is 12 MB.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:307200'], // 300 MB, matches AppReleaseResource maxSize
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 10,
        'cleanup' => true,
    ],
];
