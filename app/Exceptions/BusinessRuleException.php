<?php

namespace App\Exceptions;

use RuntimeException;

/** A rule of the library domain was violated; the message is safe to show to staff. */
class BusinessRuleException extends RuntimeException
{
}
