<?php

use App\Models\Generation;
use App\Pixelforge\Recovery;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pixelforge:recover {--resume= : Local generation UUID paused for review}', function (Recovery $recovery) {
    if ($id = $this->option('resume')) {
        Generation::whereKey($id)->whereNull('recover_at')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('final_bills')->whereColumn('generation_id', 'generations.id');
        })->update(['recover_at' => now(), 'attempts' => 0]);
    }
    $ids = Generation::where('recover_at', '<=', now())->orderBy('recover_at')->limit(20)->pluck('id');
    foreach ($ids as $id) {
        $recovery->run($id);
    }
})->purpose('Reconcile due submissions and missing webhook updates');
