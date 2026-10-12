<?php

use App\Services\DemoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('lab:seed', function () {
    app(DemoService::class)->seed();
    $this->info('Synthetic corpora indexed.');
});
Artisan::command('lab:purge', function () {
    $this->info('Purged '.app(DemoService::class)->purge().' expired workspaces.');
});
Schedule::command('lab:purge')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Artisan::command('lab:export', function () {
    $row = DB::table('evaluation_runs')->latest()->first();
    if ($row) {
        file_put_contents(base_path('docs/evaluation-latest.json'), json_encode(json_decode($row->results, true), JSON_PRETTY_PRINT));
        $this->info('Exported latest measured run.');
    }
});

Artisan::command('lab:rebuild-corpus', function () {
    $id = app(DemoService::class)->template();
    $docs = DB::table('documents')->where('workspace_id', $id)->pluck('id');
    DB::table('chunks')->where('workspace_id', $id)->delete();
    DB::table('document_versions')->whereIn('document_id', $docs)->delete();
    DB::table('documents')->where('workspace_id', $id)->delete();
    app(DemoService::class)->seed();
    $this->info('Rebuilt only the synthetic template corpus.');
});

Schedule::call(function () {
    foreach (glob(base_path('.local/backups/*.dump')) ?: [] as $file) {
        if (filemtime($file) < time() - 7200) {
            unlink($file);
        }
    }
})->everyFiveMinutes();
