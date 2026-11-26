<?php

namespace App\Support;

/** Thrown instead of calling the mobile API again while it is known to be unreachable (see ApiCircuit). */
class ApiRecentlyUnreachable extends \RuntimeException
{
}
