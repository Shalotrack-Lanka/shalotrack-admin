<?php

namespace Tests\Feature;

use App\Support\BankSlips;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bank slips are payment documents. These tests prove (simple words):
 *   - a new slip is saved on the PRIVATE disk and never on the public one,
 *   - an old slip that is still on the public disk can still be found and deleted until it is moved,
 *   - `bank-slips:make-private` changes nothing without --apply, moves every slip with --apply, keeps the
 *     public copy when the copy cannot be verified, never overwrites a different file, and is safe to repeat,
 *   - the built-in /storage/{path} route (GET and PUT) is gone, and the PDF generator may not fetch remote URLs.
 */
class BankSlipPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private_slips');
    }

    public function test_a_new_slip_is_stored_on_the_private_disk_only(): void
    {
        $path = BankSlips::store(UploadedFile::fake()->image('slip.jpg'));

        $this->assertStringStartsWith('bank_slips/', $path);
        Storage::disk('private_slips')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_the_slip_is_found_on_the_private_disk_first_then_on_the_old_public_disk(): void
    {
        Storage::disk('public')->put('bank_slips/old.pdf', 'OLD');
        Storage::disk('private_slips')->put('bank_slips/new.pdf', 'NEW');

        $this->assertSame('OLD', BankSlips::diskHolding('bank_slips/old.pdf')->get('bank_slips/old.pdf'));
        $this->assertSame('NEW', BankSlips::diskHolding('bank_slips/new.pdf')->get('bank_slips/new.pdf'));
        $this->assertNull(BankSlips::diskHolding('bank_slips/missing.pdf'));
    }

    public function test_deleting_a_slip_removes_it_from_both_disks(): void
    {
        Storage::disk('public')->put('bank_slips/a.pdf', 'A');
        Storage::disk('private_slips')->put('bank_slips/b.pdf', 'B');

        BankSlips::delete('bank_slips/a.pdf');
        BankSlips::delete('bank_slips/b.pdf');

        Storage::disk('public')->assertMissing('bank_slips/a.pdf');
        Storage::disk('private_slips')->assertMissing('bank_slips/b.pdf');
    }

    public function test_the_move_command_changes_nothing_without_apply(): void
    {
        Storage::disk('public')->put('bank_slips/a.pdf', 'A');

        $this->artisan('bank-slips:make-private')->assertSuccessful();

        Storage::disk('public')->assertExists('bank_slips/a.pdf');
        Storage::disk('private_slips')->assertMissing('bank_slips/a.pdf');
    }

    public function test_the_move_command_moves_every_slip_with_apply_and_is_safe_to_repeat(): void
    {
        Storage::disk('public')->put('bank_slips/a.pdf', 'AAA');
        Storage::disk('public')->put('bank_slips/b.pdf', 'BBBB');

        $this->artisan('bank-slips:make-private', ['--apply' => true])->assertSuccessful();

        foreach (['a.pdf' => 'AAA', 'b.pdf' => 'BBBB'] as $name => $content) {
            Storage::disk('public')->assertMissing("bank_slips/{$name}");
            $this->assertSame($content, Storage::disk('private_slips')->get("bank_slips/{$name}"));
        }

        $this->artisan('bank-slips:make-private', ['--apply' => true])->assertSuccessful();
        $this->assertSame('AAA', Storage::disk('private_slips')->get('bank_slips/a.pdf'));
    }

    public function test_the_move_command_finishes_a_half_done_move_without_losing_the_slip(): void
    {
        // The copy already exists privately (an earlier run stopped before deleting the public one).
        Storage::disk('public')->put('bank_slips/a.pdf', 'AAA');
        Storage::disk('private_slips')->put('bank_slips/a.pdf', 'AAA');

        $this->artisan('bank-slips:make-private', ['--apply' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing('bank_slips/a.pdf');
        $this->assertSame('AAA', Storage::disk('private_slips')->get('bank_slips/a.pdf'));
    }

    public function test_the_move_command_never_overwrites_a_different_private_file(): void
    {
        Storage::disk('public')->put('bank_slips/a.pdf', 'PUBLIC-VERSION');
        Storage::disk('private_slips')->put('bank_slips/a.pdf', 'X');

        $this->artisan('bank-slips:make-private', ['--apply' => true])->assertFailed();

        $this->assertSame('X', Storage::disk('private_slips')->get('bank_slips/a.pdf'));
        $this->assertSame('PUBLIC-VERSION', Storage::disk('public')->get('bank_slips/a.pdf'));
    }

    public function test_the_built_in_storage_route_is_gone(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertFalse((bool) config('filesystems.disks.private_slips.serve'));
        $this->assertSame('private', config('filesystems.disks.private_slips.visibility'));

        $this->get('/storage/anything.txt')->assertNotFound();
        $this->put('/storage/anything.txt', [])->assertStatus(404);
    }

    public function test_the_pdf_generator_may_not_fetch_remote_addresses(): void
    {
        $this->assertFalse((bool) config('dompdf.options.isRemoteEnabled'));
        $this->assertFalse((bool) config('dompdf.options.enable_remote'));
    }
}
