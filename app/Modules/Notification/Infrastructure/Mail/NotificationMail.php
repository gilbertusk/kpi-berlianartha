<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Mail;

use App\Modules\Notification\Infrastructure\Persistence\Models\UserNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email copy of a notification. It links to the in-app notification, which
 * requires sign-in and sends the reader on to the record.
 */
final class NotificationMail extends Mailable
{
    public function __construct(public UserNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->title);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.notification-text',
            with: [
                'title' => $this->notification->title,
                'body' => $this->notification->body,
                'url' => route('notifications.open', $this->notification),
            ],
        );
    }
}
