<?php

namespace App\Console\Commands\Backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class SingleStoreS3Restore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'singlestore:restore';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore a database backup from our list of backups in S3.';

    protected function isFileSystemSetup()
    {

        $s3_creds = config('filesystems.disks.singlestore_backup_manager');

        if (!isset($s3_creds['key']) || !isset($s3_creds['secret']) || !isset($s3_creds['region'])) {
            return false;
        }

        return true;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {

        if (!$this->isFileSystemSetup()) {
            $this->error('Filesystem not setup correctly. Please make sure you have the correct values in your `.env` file.');

            return;
        }

        if (!$this->ensureNotProduction()) {
            $this->error("Can't run this command in any other env other than local or development.");

            return;
        }

        $files = Storage::disk('singlestore_backup_manager')->listContents('singlestore');
        $options = collect($files->toArray())->filter(fn ($file) => $file->type() == 'dir')
            ->map(function ($file) {
                $path = $file->path();
                $split_path = collect(explode('-', $path));
                $env = $split_path->pop();

                try {
                    $date = \Carbon\Carbon::createFromFormat('Y-m-d-H-i-s', (string) Str::of($split_path->implode('-'))->replace('singlestore/', ''));
                } catch (\Throwable $e) {
                    return null;
                }

                return [
                    'path' => $path,
                    'environment' => $env,
                    'date' => $date,
                    'date_formatted' => $date->format('jS F Y, H:ia'),
                    'timestamp' => $date->timestamp,
                ];
            })
            ->filter(fn ($item) => $item !== null)
            ->sortByDesc('timestamp');

        $backup_path = select(
            'Which backup would you like to restore?',
            $options->pluck('date_formatted', 'path')->toArray()
        );

        $localDbConnection = config('database.connections.singlestore');
        $s3_creds = config('filesystems.disks.singlestore_backup_manager');

        $config = json_encode([
            'region' => $s3_creds['region'],
        ]);

        $credentials = json_encode([
            'aws_access_key_id' => $s3_creds['key'],
            'aws_secret_access_key' => $s3_creds['secret'],
        ]);

        $backupDBName = 'socialsync_production';

        $restore_sql = <<<SQL
            RESTORE DATABASE {$backupDBName} as {$localDbConnection['database']} FROM S3 "socialsync-db-backups/{$backup_path}"
            CONFIG '{$config}'
            CREDENTIALS '{$credentials}';
        SQL;

        if (
            confirm("Are you sure you want to restore the backup {socialsync-db-backups/{$backup_path}?", false) &&
            confirm('ARE YOU SUPER SURE?', false)
        ) {
            $this->info('Dropping your current database...');
            DB::statement('DROP DATABASE IF EXISTS ' . $localDbConnection['database'] . ';');
            $this->info('Done');
            $this->info('Restoring your database from backup...');
            DB::statement($restore_sql);
            $this->info("All done! Don't forget to run your migrations.");

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    private function ensureNotProduction()
    {
        return app()->environment('local') || app()->environment('staging') || app()->environment('development');
    }
}
