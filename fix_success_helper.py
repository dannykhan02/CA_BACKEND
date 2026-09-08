#!/usr/bin/env python3
"""
Aligns updateNotificationPreferences() with the rest of AuthController's
conventions: $this->success() helper, forceFill()->save(), JsonResponse
return type. Aborts with no changes if the current method doesn't match
byte-for-byte what we last confirmed was deployed.

Run from your Laravel project root: python3 fix_success_helper.py
"""

PATH = "app/Http/Controllers/Api/AuthController.php"

with open(PATH) as f:
    content = f.read()

old = """    public function updateNotificationPreferences(\\App\\Http\\Requests\\UpdateNotificationPreferencesRequest $request)
    {
        $user = $request->user();

        $user->notification_preferences = [
            'processing_complete' => $request->boolean('processing_complete'),
            'review_requested' => $request->boolean('review_requested'),
            'power_bi_sync' => $request->boolean('power_bi_sync'),
        ];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences updated.',
            'data' => ['user' => $this->userPayload($user)],
        ]);
    }
}"""

new = """    public function updateNotificationPreferences(\\App\\Http\\Requests\\UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'notification_preferences' => [
                'processing_complete' => $request->boolean('processing_complete'),
                'review_requested' => $request->boolean('review_requested'),
                'power_bi_sync' => $request->boolean('power_bi_sync'),
            ],
        ])->save();

        return $this->success('Notification preferences updated.', [
            'user' => $this->userPayload($user),
        ]);
    }
}"""

if old not in content:
    raise SystemExit(
        "ABORTED: expected method body not found — file may already differ.\n"
        "No changes written."
    )

content = content.replace(old, new)

with open(PATH, "w") as f:
    f.write(content)

print(f"Fixed: {PATH}")
print("\nVerify:")
print(f"  php -l {PATH}")
print("  git diff")