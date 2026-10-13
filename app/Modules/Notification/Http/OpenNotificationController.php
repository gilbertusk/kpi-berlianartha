<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http;

use App\Models\User;
use App\Modules\Notification\Application\NotificationService;
use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface;

/**
 * Deep link of a notification. Only its recipient can use it; it marks the
 * notification read and sends the reader to the record's page, which runs
 * its own authorization check. A link whose target no longer resolves falls
 * back to the notification list.
 */
final readonly class OpenNotificationController
{
    public function __invoke(Request $request, UserNotification $notification, NotificationService $notifications): RedirectResponse
    {
        $user = $request->user();

        // Someone else's notification does not exist for this account.
        abort_unless($user instanceof User && $notification->user_id === $user->id, 404);

        $notifications->markRead($notification);

        if ($notification->route_name === null || ! Route::has($notification->route_name)) {
            return redirect()->route('notifications.index');
        }

        try {
            return redirect()->route($notification->route_name, $notification->route_parameters ?? []);
        } catch (ExceptionInterface) {
            return redirect()->route('notifications.index');
        }
    }
}
