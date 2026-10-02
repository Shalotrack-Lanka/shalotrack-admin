<?php

namespace App\Enums;

/** Life of a technician job. Only a scheduled job can still be changed; the other two are final. */
enum TechnicianJobStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}