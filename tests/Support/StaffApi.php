<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Helpers for the staff app's feature tests: a team member in a branch, the
 * bearer header for them, and (when STAFF_EXAMPLES is a file path) a dump of
 * every pinned response, which the mobile app is written against.
 */
trait StaffApi
{
    private function staffUser(string $role, Branch $branch, array $state = []): User
    {
        $user = User::factory()->state(['role' => $role, ...$state])->create();
        $user->branches()->attach($branch);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        [$plain] = UserToken::issue($user, 'Test phone');

        return ['Authorization' => 'Bearer '.$plain];
    }

    /**
     * Pins nothing by itself: adds this response to the examples file when STAFF_EXAMPLES is set.
     */
    private function example(string $name, string $request, TestResponse $response): TestResponse
    {
        $file = getenv('STAFF_EXAMPLES');

        if ($file) {
            $all = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $body = $response->baseResponse instanceof StreamedResponse ? '(binary)' : $response->json();

            if (is_array($body) && isset($body['token'])) {
                $body['token'] = '<40 random characters, shown once>';
            }

            $all[$name] = ['request' => $request, 'status' => $response->getStatusCode(), 'body' => $body];
            ksort($all);
            file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $response;
    }
}
