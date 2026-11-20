<?php

declare(strict_types=1);

namespace Sahil\Identification;

use RuntimeException;

/**
 * Invalid identification configuration: an `identification.*` value has a
 * type the identification service cannot honor. Fails loudly with a message
 * naming the offending value, matching the framework's other configuration
 * contracts.
 */
final class InvalidIdentificationConfigurationException extends RuntimeException
{
}
