<?php

namespace Tests\Feature;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use Tests\TestCase;

class InitialsAvatarTest extends TestCase
{
    public function test_the_admin_avatar_is_a_local_svg_and_never_ui_avatars(): void
    {
        $user = User::factory()->make(['name' => 'Ana <b>Reyes</b>']);
        $url = (new InitialsAvatarProvider)->get($user);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>A&lt;</text>', $svg);
        $this->assertStringNotContainsString('ui-avatars', $url);
    }
}
