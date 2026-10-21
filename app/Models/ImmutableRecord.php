<?php

namespace App\Models;

use LogicException;

/**
 * Issued billing documents are evidence: they can be created, never changed or removed through the app.
 * (PostgreSQL triggers enforce the same in the database.) Corrections are new documents.
 */
trait ImmutableRecord
{
    public static function bootImmutableRecord(): void
    {
        static::updating(function () {
            throw new LogicException('An issued billing record cannot be changed.');
        });
        static::deleting(function () {
            throw new LogicException('An issued billing record cannot be deleted.');
        });
    }
}
