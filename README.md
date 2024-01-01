# Laravel Singlestore Utilities

A few utilities to copy-and-paste into your project to help you migrate from MySQL to Singlestore.

In theory, this should also work with Postgres, but hasn't been tested.

> Note: we provide no warranty or guarantee of maintainance for these scripts, they're simply a starting point.

**Please test extensively locally, before using in production.**

# The data migration script.

Copy the `MigrateDataToNewConnectionCommand.php` command to your `app/Console/Commands` folder.

## Getting ready

1. Make sure you've adjusted your migration files, you can see the approach we used here. (LINK TO BE ADDED TO BLOG POST).
2. Add `/storage/exported_tables` to your `.gitignore`

## Usage:

After setting up the [Laravel Singlestore Driver](https://github.com/singlestore-labs/singlestoredb-laravel-driver) package, you should have a connection in `config/database.php` called `singlestore`.

```
php artisan app:migrate-data-to-new-connection {mysql_connection_name} {singlestore_connection_name}
```

this will do a few things:

1. Loop through all the tables on the Mysql connection and export each table, including an CREATE TABLE statement into files in `storage/exported_tables` directory.
2. If you supply the `--drop-db` option, it will drop any database that exists with the configured name in your _destination_ connection - i.e., on Singlestore.
3. It will run your migrations on your destination connection.
4. It will then loop through all the exported table SQL files and run those on your _destination_ (singlestore) connection.
5. It will then clean up the files it's created.

### Options:

#### `--bulk`

If you supply the `--bulk` option, it will attempt to export the SQL from all the tables into a single file.

#### `--drop-db`

This will drop and re-create the database named in the _destination_ connection settings before exporting and re-importing, useful if you're running this script over and over to make changes for compatibility.

#### `--skip-export`

This will simply re-run the import process if you're already happy with the exported files you have.

# The S3 Backup/Restore commands.

There are two commands here: `SingleStoreS3Restore.php` and `SingleStoreS3Backup.php` - these allow you to use Singlestore's VERY fast [backup and restore](https://docs.singlestore.com/cloud/reference/sql-reference/operational-commands/backup-database/) features to create backups and also to restore them quickly.

> Note: In order to restore a backup to another Singlestore instance, it should be running the same version of Singlestore as the instance that it backed up from, otherwise you may receive an error.

## Configuration

You'll need to add a few things to your Laravel app's configuration:

In `filesystems.php`, add a new filesystem for the singlestore backup manager to keep things isolated and reduce potential side effects:

```php

'disks' => [
    'singlestore_backup_manager' => [
        'driver' => 's3',
        'key' => env('SINGLESTORE_BACKUP_MANAGER_S3_KEY', null),
        'secret' => env('SINGLESTORE_BACKUP_MANAGER_S3_SECRET'),
        'region' => env('SINGLESTORE_BACKUP_MANAGER_S3_REGION'),
        'bucket' => env('SINGLESTORE_BACKUP_MANAGER_S3_BUCKET'),
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],
]
```

Then add the following env variables to your `.env` file:

```env
SINGLESTORE_BACKUP_MANAGER_S3_BUCKET="your-bucket-name"
SINGLESTORE_BACKUP_MANAGER_S3_REGION="eu-west-2"
SINGLESTORE_BACKUP_MANAGER_S3_KEY="<key-here>"
SINGLESTORE_BACKUP_MANAGER_S3_SECRET="<secret-here>"
```

### Backing up

Once this is done, you can then run a backup by simply running:

```
php artisan singlestore:backup
```

And of course you can schedule this in your `app/Console/Kernel.php` file:

```php
$schedule
    ->command('singlestore:backup')
    ->withoutOverlapping()
    ->onOneServer()
    ->dailyAt('01:00');
```

### Restoring

> Note: This script assumes you have `laravel/prompts` installed.

Restoring is super useful for pulling a copy of your live database and restoring it to your local/staging/development environment for debugging. Just be aware that production data should always be guarded against misuse.

To restore a backup, just run:

```
php artisan singlestore:restore
```

You'll be presented with a list of your backups to choose from to restore, once you've chosen one, it will ask you multiple times if you're sure you want to restore it.
