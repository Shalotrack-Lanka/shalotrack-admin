<?php

namespace App\Exceptions;

/** A payout was refused for a business reason. The message is safe to show to staff. */
class CommissionPayoutException extends \RuntimeException
{
}