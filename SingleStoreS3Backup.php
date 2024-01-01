<?php

namespace App\Console\Commands\Backups;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SinglestoreS3Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'singlestore:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backs up singlestore using the BACKUP DATABASE command.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = date('Y-m-d-H-i-s-') . app()->environment();

        $json_credentials = json_encode([
            'aws_access_key_id' => config('services.singlestore.backup_key'),
            'aws_secret_access_key' => config('services.singlestore.backup_secret'),
        ]);

        $database = config('database.connections.singlestore.database');

        $sql = <<<SQL
        BACKUP DATABASE {$database} TO S3 "socialsync-db-backups/singlestore/{$date}"
        CONFIG '{"region":"eu-west-2"}'
        CREDENTIALS '{$json_credentials}';
        SQL;

        DB::statement($sql);
        $this->info('Done');
    }
}
