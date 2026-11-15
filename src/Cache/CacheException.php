<?php

declare(strict_types=1);

namespace Sahil\Cache;

use RuntimeException;

/** Descriptive cache driver failures — never a silent miss. */
final class CacheException extends RuntimeException
{
}
