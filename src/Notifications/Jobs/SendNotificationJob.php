<?php

declare(strict_types=1);

namespace Sahil\Notifications\Jobs;

use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Contracts\Notifications\RecipientResolverContract;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\NotificationReport;
use Sahil\Notifications\Notify;
use Sahil\Orm\Model;
use Sahil\Queue\Jobs\Job;

/**
 * Queued delivery of one notification to one recipient.
 *
 * Serialization strategy: the *recipient travels as a reference*
 * `(class, id)` — never as a serialized model — so a queued job can't
 * deliver to stale data or drag a database connection into the payload.
 * The notification object itself must be plain serializable data
 * (scalars/arrays); it is serialized by the queue verbatim.
 *
 * At execution the recipient is re-resolved through the bound
 * {@see RecipientResolverContract}, or through `Model::find()` when the
 * class is an ORM model. An unresolvable recipient fails the job loudly
 * — it enters the standard retry/failed-job flow instead of silently
 * dropping the notification.
 */
final class SendNotificationJob extends Job
{
    private readonly string $notifiableType;

    private readonly string $notifiableId;

    public function __construct(
        NotifiableContract $notifiable,
        private readonly NotificationContract $notification,
    ) {
        $route = $notifiable->routeNotificationFor('database')
            ?? $notifiable->routeNotificationFor('mail')
            ?? throw new NotificationException(sprintf(
                'Cannot queue a notification for [%s]: it exposes no route for any channel.',
                $notifiable::class,
            ));

        $this->notifiableType = $notifiable::class;
        $this->notifiableId = $route;
    }

    public function handle(): NotificationReport
    {
        $notifiable = $this->resolveNotifiable();

        return Notify::send($notifiable, $this->notification);
    }

    private function resolveNotifiable(): NotifiableContract
    {
        // A bound resolver wins — apps with non-ORM recipients plug in here.
        if (\Sahil\Foundation\Application::peek() !== null
            && app()->container()->has(RecipientResolverContract::class)) {
            /** @var RecipientResolverContract $resolver */
            $resolver = app()->container()->make(RecipientResolverContract::class);
            $resolved = $resolver->resolve($this->notifiableType, $this->notifiableId);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        if (is_subclass_of($this->notifiableType, Model::class)) {
            $model = $this->notifiableType::find($this->notifiableId);

            if ($model instanceof NotifiableContract) {
                return $model;
            }
        }

        throw new NotificationException(sprintf(
            'Cannot resolve notifiable [%s#%s] — is it an ORM model, or is a %s bound?',
            $this->notifiableType,
            $this->notifiableId,
            RecipientResolverContract::class,
        ));
    }
}
