<?php

namespace App\Console\Commands;

use App\Models\DtrUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DeleteExpiredDtrs extends Command
{
    protected $signature   = 'dtr:delete-expired';
    protected $description = 'Delete DTR uploads whose auto_delete_at has passed';

    public function handle(): void
    {
        $expired = DtrUpload::where('auto_delete_at', '<', now())->get();

        foreach ($expired as $dtr) {
            Storage::disk('local')->delete($dtr->file_path);
            $dtr->delete();
        }

        $this->info("Deleted {$expired->count()} expired DTR upload(s).");
    }
}
