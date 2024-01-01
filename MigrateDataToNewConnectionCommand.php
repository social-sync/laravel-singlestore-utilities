<?php

namespace App\Console\Commands\Singlestore;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;

class MigrateDataToNewConnectionCommand extends Command
{
    public array $importIgnore = [
        'personal_access_tokens',
        'mysql',
        'migrations',
        'failed_jobs',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-data-to-new-connection {source} {destination} {--bulk} {--drop-db} {--skip-export}';

    public static array $increment_counts = [];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrates data, one table at a time, to a new database connection by exporting to storage and then importing.';

    public function handle()
    {
        $sourceConnection = $this->argument('source');

        $destinationConnection = $this->argument('destination');

        if ($this->option('drop-db')) {
            DB::connection($destinationConnection)->statement('DROP DATABASE IF EXISTS ' . config("database.connections.{$destinationConnection}.database"));
            DB::connection($destinationConnection)->statement('CREATE DATABASE ' . config("database.connections.{$destinationConnection}.database"));
        }

        if (!$this->option('skip-export')) {

            // Export data from source connection
            if ($this->option('bulk')) {
                $this->exportAllTables($sourceConnection);
            } else {
                $this->exportTables($sourceConnection);
            }
        }

        $this->call($this->option('drop-db') ? 'migrate' : 'migrate:fresh', [
            '--database' => $destinationConnection,
        ]);

        // Import data into destination connection
        if ($this->option('bulk')) {
            $this->importBulkTables($destinationConnection, $sourceConnection);
        } else {
            $this->importTables($destinationConnection);
        }

        $this->components->task('Updating AUTO_INCREMENT on AGGREGATOR', function () use ($destinationConnection) {
            DB::connection($destinationConnection)->statement('AGGREGATOR SYNC AUTO_INCREMENT;');
        });

        if (confirm(
            label: 'Run row count checks?',
            default: true,
        )) {
            $this->runRowCountChecks($destinationConnection);
        }

        if (confirm(
            label: 'All done! Clean up exported SQL files?',
            default: true,
        )) {
            $this->cleanUpExportedFiles();
        }

        $this->info('Data export-import completed successfully!');
    }

    private function runRowCountChecks($destinationConnection)
    {
        $tables = DB::connection($destinationConnection)->getDoctrineSchemaManager()->listTableNames();
        $outputDirectory = storage_path('exported_tables'); // Directory to store exported SQL files

        foreach ($tables as $table) {

            $rowCount = DB::connection($destinationConnection)->table($table)->count();

            if (File::exists($outputDirectory . '/' . $table . '.rowcount')) {
                $rowCountFromSource = intval(File::get($outputDirectory . '/' . $table . '.rowcount'));
                if ($rowCount !== $rowCountFromSource) {
                    $this->error("Row count mismatch for table {$table}! Expected {$rowCountFromSource} but got {$rowCount}.");
                }
            }
        }
    }

    private function exportTables($connection)
    {
        $tables = DB::connection($connection)->getDoctrineSchemaManager()->listTableNames();
        $outputDirectory = storage_path('exported_tables'); // Directory to store exported SQL files

        if (!file_exists($outputDirectory)) {
            if (!mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDirectory));
            }
        }

        foreach ($tables as $table) {

            $rowCount = DB::connection($connection)->table($table)->count();

            File::put($outputDirectory . '/' . $table . '.rowcount', $rowCount);

            $fileName = $outputDirectory . '/' . $table . '.sql';

            $exportCommand = sprintf(
                'mysqldump --single-transaction -u %s -p%s --no-create-info --compact %s %s > %s',
                config("database.connections.{$connection}.username"),
                config("database.connections.{$connection}.password"),
                config("database.connections.{$connection}.database"),
                $table,
                $fileName
            );
            $this->components->task('Exporting table ' . $table, function () use ($exportCommand) {
                Process::timeout(360)->run($exportCommand)->throw();
            });
        }
    }

    private function getAutoIncrementCountForAllTables(string $from_connection, string $to_connection): void
    {

        $inputDirectory = storage_path('exported_tables'); // Directory containing exported SQL files

        $files = glob($inputDirectory . '/*.sql');

        foreach ($files as $file) {

            $tableName = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($tableName, $this->importIgnore)) {
                continue;
            }

            // get table status.
            $status = DB::connection($from_connection)->select('SHOW TABLE STATUS WHERE name = ?', [$tableName]);

            if (isset($status['Auto_increment'])) {
                self::$increment_counts[$tableName] = $status['Auto_increment'];
            }
        }
    }

    private function exportAllTables($connection)
    {
        $outputDirectory = storage_path('exported_tables'); // Directory to store exported SQL files

        if (!file_exists($outputDirectory)) {
            if (!mkdir($outputDirectory, 0777, true) && !is_dir($outputDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $outputDirectory));
            }
        }

        $fileName = storage_path("exported_tables/{$connection}.sql");

        $exportCommand = sprintf(
            'mysqldump --single-transaction -u %s -p%s --no-create-info --compact %s > %s',
            config("database.connections.{$connection}.username"),
            config("database.connections.{$connection}.password"),
            config("database.connections.{$connection}.database"),
            $fileName
        );

        $this->components->task('Exporting tables', function () use ($exportCommand) {
            Process::timeout(360)->run($exportCommand)->throw();
        });
    }

    private function importTables($connection)
    {
        $inputDirectory = storage_path('exported_tables'); // Directory containing exported SQL files

        $files = glob($inputDirectory . '/*.sql');

        foreach ($files as $file) {
            $tableName = pathinfo($file, PATHINFO_FILENAME);

            if (in_array($tableName, $this->importIgnore)) {
                continue;
            }

            $truncateCommand = sprintf(
                'mysql -u %s -h %s -p%s -P%s %s -e "TRUNCATE TABLE %s;"',
                config("database.connections.{$connection}.username"),
                config("database.connections.{$connection}.host"),
                config("database.connections.{$connection}.password"),
                config("database.connections.{$connection}.port"),
                config("database.connections.{$connection}.database"),
                $tableName
            );

            $this->components->task('Truncating table ' . $tableName, function () use ($truncateCommand) {
                Process::timeout(360)->run($truncateCommand)->throw();
            });

            $importCommand = sprintf(
                'mysql -u %s -h %s -p%s -P%s %s < %s',
                config("database.connections.{$connection}.username"),
                config("database.connections.{$connection}.host"),
                config("database.connections.{$connection}.password"),
                config("database.connections.{$connection}.port"),
                config("database.connections.{$connection}.database"),
                $file
            );

            $this->components->task('Importing table ' . $tableName, function () use ($importCommand) {
                Process::timeout(500)->run($importCommand)->throw();
            });
        }
    }

    private function cleanUpExportedFiles()
    {
        $outputDirectory = storage_path('exported_tables'); // Directory containing exported SQL files

        if (file_exists($outputDirectory)) {
            File::cleanDirectory($outputDirectory);
            rmdir($outputDirectory);
            $this->info('Exported SQL files cleaned up successfully!');
        }
    }

    private function importBulkTables(string $destinationConnection, string $sourceConnection)
    {
        $fileName = storage_path('exported_tables/' . $sourceConnection . '.sql');
        $importCommand = sprintf(
            'mysql -u %s -h %s -p%s -P%s %s < %s',
            config("database.connections.{$destinationConnection}.username"),
            config("database.connections.{$destinationConnection}.host"),
            config("database.connections.{$destinationConnection}.password"),
            config("database.connections.{$destinationConnection}.port"),
            config("database.connections.{$destinationConnection}.database"),
            $fileName
        );
        $this->components->task('Importing tables', function () use ($importCommand) {
            Process::timeout(500)->run($importCommand)->throw();
        });
    }

    public function idMap()
    {
        return [
            'activity_logs' => [
                'id' => null,
                'individual_activity_type_id' => 'individual_activity_types.id',
                'individual_activity_unit_id' => 'individual_activity_units.id',
                'campaign_registration_id' => 'campaign_registrations.id',
            ],
            'activity_type_activity_unit' => [
                'activity_type_id' => 'activity_types.id',
                'activity_unit_id' => 'activity_units.id',
            ],
            'activity_types' => [
                'id' => null,
            ],
            'activity_units' => [
                'id' => null,
            ],
            'buildable_form_field_settings' => [
                'id' => null,
                'buildable_form_field_id' => 'buildable_form_fields.id',
            ],
            'buildable_form_fields' => [
                'id' => null,
                'buildable_form_id' => 'buildable_forms.id',
            ],
            'buildable_forms' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'customer_team_id' => 'customer_teams.id',
                'created_by_user_id' => 'users.id',
                'success_page_buildable_forms_id' => 'buildable_forms.id',
            ],
            'buildable_page_object_settings' => [
                'id' => null,
                'buildable_page_object_id' => 'buildable_page_objects.id',
            ],
            'buildable_page_objects' => [
                'id' => null,
                'buildable_page_id' => 'buildable_pages.id',
            ],
            'buildable_pages' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'created_by_user_id' => 'users.id',
            ],
            'campaign_copy_request_mappings' => [
                'id' => null,
                'mappable_id' => ['mappable_type', 'id'],
            ],
            'campaign_copy_requests' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'customer_team_id' => 'customer_teams.id',
                'new_customer_team_id' => 'customer_teams.id',
                'user_id' => 'users.id',
            ],
            'campaign_elements' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'individual_activity_type_id' => 'activity_types.id',
                'individual_activity_unit_id' => 'activity_units.id',
                'shared_activity_type_id' => 'activity_types.id',
                'shared_activity_unit_id' => 'activity_units.id',
            ],
            'campaign_registrations' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'supporter_id' => 'supporters.id',
                'campaign_element_id' => 'campaign_elements.id',
                'primary_social_sync_fundraiser_id' => 'fundraisers.id',
                'primary_facebook_fundraiser_id' => 'fundraisers.id',
                'primary_instagram_fundraiser_id' => 'fundraisers.id',
                'source_id' => ['source_type', 'id'],
            ],
            'campaigns' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'created_by_user_id' => 'users.id',
                'registration_buildable_form_id' => 'buildable_forms.id',
                'donation_buildable_form_id' => 'buildable_forms.id',
                'copy_of_campaign_id' => 'campaigns.id',
            ],
            'comms_logs' => [
                'id' => null,
                'supporter_id' => 'supporters.id',
                'campaign_id' => 'campaigns.id',
                'campaign_registration_id' => 'campaign_registrations.id',
                'completed_by_user_id' => 'users.id',
                'last_edited_by_user_id' => 'users.id',
                'deleted_by_user_id' => 'users.id',
                'stewardship_task_id' => 'stewardship_tasks.id',
                'touchpoint_id' => ['touchpoint_type', 'id'],
            ],
            'consent_records' => [
                'id' => null,
                'supporter_id' => 'supporters.id',
                'campaign_element_id' => 'campaign_elements.id',
                'source_id' => ['source_type', 'id'],
            ],
            'customer_team_invitations' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'existing_user_id' => 'users.id',
            ],
            'customer_team_user' => [
                'customer_team_id' => 'customer_teams.id',
                'user_id' => 'users.id',
            ],
            'customer_teams' => [
                'id' => null,
                'created_by_user_id' => 'users.id',
                'plan_id' => 'plans.id',
                'anon_supporter_id' => 'supporters.id',
            ],
            'data_batches' => [
                'id' => null,
                'user_id' => 'users.id',
                'customer_team_id' => 'customer_teams.id',

            ],
            'deleted_models' => [
                'id' => null,
                'key' => ['model_type', 'id'],
            ],
            'element_form' => [
                'id' => null,
                'campaign_element_id' => 'campaign_elements.id',
                'buildable_form_id' => 'buildable_forms.id',
            ],
            'export_requests' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'user_id' => 'users.id',
                'data_batch_id' => 'data_batches.id',
            ],
            'facebook_fundraiser_failure_logs' => [
                'id' => null,
                'facebook_fundraiser_id' => 'facebook_fundraisers.id',
                'fundraiser_id' => 'fundraisers.id',
                'campaign_registration_id' => 'campaign_registrations.id',
            ],
            'facebook_fundraiser_post_touchpoints' => [
                'customer_team_id' => 'customer_teams.id',
            ],
            'facebook_fundraisers' => [
                'id' => null,
                'fundraiser_id' => 'fundraisers.id',
            ],
            'facebook_page_associations' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
            ],
            'facebook_page_nightly_api_imports' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'pending_facebook_transaction_api_import_id' => 'pending_facebook_transaction_api_imports.id',
                'facebook_page_id' => 'facebook_pages.id',
            ],
            'facebook_tokens' => [
                'id' => null,
                'tokenable_id' => ['tokenable_type', 'id'],
            ],
            'facebook_transactions' => [
                'id' => null,
                'transaction_id' => 'transactions.id',
                'import_id' => 'imports.id',
            ],
            'form_submission_associations' => [
                'id' => null,
                'form_submission_id' => 'form_submissions.id',
                'form_submission_association_id' => ['form_submission_association_type', 'id'],
            ],
            'form_submission_data' => [
                'id' => null,
                'form_submission_id' => 'form_submissions.id',
                'buildable_form_field_id' => 'buildable_form_fields.id',
            ],
            'form_submissions' => [
                'id' => null,
                'buildable_form_id' => 'buildable_forms.id',
                'campaign_id' => 'campaigns.id',
                'transaction_id' => 'transactions.id',
            ],
            'form_visits' => [
                'id' => null,
                'buildable_form_id' => 'buildable_forms.id',
                'campaign_element_id' => 'campaign_elements.id',
            ],
            'fundraisers' => [
                'id' => null,
                'supporter_id' => 'supporters.id',
                'campaign_element_id' => 'campaign_elements.id',
                'campaign_registration_id' => 'campaign_registrations.id',
                'last_modified_by_user_id' => 'users.id',
            ],
            'import_rules' => [
                'id' => null,
                'campaign_element_id' => 'campaign_elements.id',
                'customer_team_id' => 'customer_teams.id',
            ],
            'imports' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'importable_id' => ['importable_type', 'id'],
            ],
            'journey_actions' => [
                'id' => null,
                'customer_team_id' => 'customer_teams.id',
                'campaign_id' => 'campaigns.id',
                'rule_id' => 'journey_rules.id',
                'touchpoint_id' => ['touchpoint_type', 'id'],
            ],
            'journey_conditions' => [
                'id' => null,
                'rule_id' => 'journey_rules.id',
                'trigger_id' => 'journey_triggers.id',
            ],
            'journey_rules' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
            ],
            'journey_scheduled_triggers' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'rule_id' => 'journey_rules.id',
                'trigger_id' => 'journey_triggers.id',
                'target_id' => 'campaign_registrations.id',
            ],
            'journey_triggers' => [
                'id' => null,
                'campaign_id' => 'campaigns.id',
                'rule_id' => 'journey_rules.id',
            ],
            'lookup_age_ranges' => [
                'id' => null,
            ],
            'lookup_genders' => [
                'id' => null,
            ],
            'lookup_motivations' => [
                'id' => null,
            ],
            //            lookup_name_pronouns
            //        lookup_name_titles
            //        lookup_timezones
        ];
    }
}
