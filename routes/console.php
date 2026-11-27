<?php

use App\Models\Generation;
use App\Pixelforge\Recovery;
use Illuminate\Support\Facades\Artisan;

Artisan::command('pixelforge:recover {--resume= : Local generation UUID paused for review}', function (Recovery $recovery) {
    if ($id = $this->option('resume')) {
        $recovery->run($id, operatorRecheck: true);

        return;
    }
    $ids = Generation::where('recover_at', '<=', now())->orderBy('recover_at')->limit(20)->pluck('id');
    foreach ($ids as $id) {
        $recovery->run($id);
    }
})->purpose('Reconcile due submissions and missing webhook updates');
