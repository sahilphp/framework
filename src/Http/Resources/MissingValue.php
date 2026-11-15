<?php

declare(strict_types=1);

namespace Sahil\Http\Resources;

/**
 * Sentinel for "this key should not appear in the output at all".
 *
 * `null` is a legitimate API value — it can never mean "omit". Conditional
 * helpers ({@see Resource::when()}, {@see Resource::whenNotNull()},
 * {@see Resource::whenLoaded()}) return this instead, and the serializer
 * drops the key entirely. It survives as a marker only inside
 * `toArray()`; it never reaches the wire.
 */
final class MissingValue
{
    public function __construct()
    {
    }
}
