<?php

namespace App\Console\Commands;

use App\Support\BankSlips;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MoveBankSlipsToPrivate extends Command
{
    protected $signature = 'bank-slips:make-private {--apply : Really move the files. Without it nothing changes.}';

    protected $description = 'Copies every bank slip from the public disk to the private disk, checks the copy, and only then removes the public copy. Safe to run again. Without --apply it only reports what it would do.';

    public function handle(): int
    {
        $public = Storage::disk(BankSlips::LEGACY_DISK);
        $private = Storage::disk(BankSlips::PRIVATE_DISK);
        $apply = (bool) $this->option('apply');

        $files = $public->files(BankSlips::FOLDER);
        $moved = $skipped = $failed = 0;

        foreach ($files as $path) {
            // Never overwrite a different file that is already private.
            if ($private->exists($path) && $private->size($path) !== $public->size($path)) {
                $this->error("{$path}: a different file already exists on the private disk. Left alone.");
                $failed++;
                continue;
            }

            if (! $apply) {
                $this->line("would move {$path}");
                $skipped++;
                continue;
            }

            if (! $private->exists($path)) {
                $stream = $public->readStream($path);
                if (! is_resource($stream) || ! $private->writeStream($path, $stream)) {
                    $this->error("{$path}: copy failed. Public copy kept.");
                    $failed++;
                    continue;
                }
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Delete the public copy only when the private copy is there and the same size.
            if ($private->exists($path) && $private->size($path) === $public->size($path)) {
                $public->delete($path);
                $moved++;
            } else {
                $this->error("{$path}: copy could not be verified. Public copy kept.");
                $failed++;
            }
        }

        $this->info($apply
            ? "Moved {$moved} slip(s); {$failed} problem(s)."
            : count($files) . ' slip(s) on the public disk. Nothing changed (run again with --apply to move them).');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
