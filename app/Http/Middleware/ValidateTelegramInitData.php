<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TelegramInitDataValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTelegramInitData
{
    public function __construct(
        private readonly TelegramInitDataValidator $validator,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->header('X-Telegram-Init-Data', '');

        $fields = $initData !== ''
            ? $this->validator->validate($initData, (string) config('services.telegram.bot_token'))
            : null;

        if ($fields === null || ! isset($fields['user']['id'])) {
            return response()->json(['error' => ['message' => 'Invalid or missing Telegram session', 'type' => 'unauthorized']], 401);
        }

        $tgUser = $fields['user'];

        $user = User::updateOrCreate(
            ['telegram_id' => $tgUser['id']],
            [
                'username' => $tgUser['username'] ?? null,
                'photo_url' => $tgUser['photo_url'] ?? null,
                'first_name' => $tgUser['first_name'] ?? 'Player',
            ]
        );

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
