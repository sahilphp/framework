<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

/**
 * A notification that can be delivered over the `database` channel.
 *
 * The notification composes the stored payload; persistence, scoping and
 * read-state are the channel's/store's job. The payload must be
 * JSON-serializable — scalars, arrays, nulls — since it is stored verbatim.
 *
 * @return array<string, mixed>
 */
interface DatabaseNotificationContract extends NotificationContract
{
    /**
     * @return array<string, mixed>
     */
    public function toDatabase(NotifiableContract $notifiable): array;
}
