<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

/**
 * Resolves a `(class, id)` notifiable reference back to a live object —
 * used by queued notification delivery, where the recipient travels as
 * a reference rather than a serialized model.
 *
 * The framework resolves `Sahil\Orm\Model` subclasses via `find()` out of
 * the box; bind this contract when notifiables live elsewhere.
 */
interface RecipientResolverContract
{
    /**
     * @param class-string $class
     */
    public function resolve(string $class, string $id): ?NotifiableContract;
}
