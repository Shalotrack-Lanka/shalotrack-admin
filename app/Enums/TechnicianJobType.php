<?php

namespace App\Enums;

/** What a technician is sent to do. Stored as the backing string in technician_jobs.job_type. */
enum TechnicianJobType: string
{
    case Install = 'install';
    case Repair  = 'repair';
    case Replace = 'replace';
    case Removal = 'removal';

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Install',
            self::Repair  => 'Repair',
            self::Replace => 'Replace device',
            self::Removal => 'Removal',
        };
    }

    /** Repair, replace and removal all need a device that is already bound to the vehicle. */
    public function needsBoundDevice(): bool
    {
        return $this !== self::Install;
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}