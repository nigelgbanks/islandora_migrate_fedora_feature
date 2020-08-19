# Islandora Migrate Fedora Feature <!-- omit in toc -->

- [Introduction](#introduction)
- [Installation](#installation)
- [Generating Import CSV](#generating-import-csv)
  - [Drupal Data](#drupal-data)
  - [Fedora Data](#fedora-data)
- [Running the migrations](#running-the-migrations)
- [How this migration works](#how-this-migration-works)
- [Advanced Drush commands](#advanced-drush-commands)
  - [Import command](#import-command)
  - [Control the migration](#control-the-migration)
    - [Rollbacks](#rollbacks)

## Introduction

For use in combination with the [migration] cli tool, allows users to migrate
from an existing Fedora 3 repository folder without a running Fedora /
Islandora.

## Installation

Download this feature, and its dependencies with composer ensure you have the
appropriate repository setup in your `composer.json` file.

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/lyrasis/islandora_migrate_fedora_feature"
    }
],
```

```bash
composer require islandora-lyrasis/islandora_migrate_fedora_feature dev-master
```

Install the module and example migrations at the same time using Drush

```bash
drush en islandora_migrate_fedora_feature
```

## Generating Import CSV

There are a number of CSV files expected to be placed in the `artifacts`
directory for this migration to run successfully.

### Drupal Data

`artifacts/drupal/users.csv`

Assuming you have access to the running Drupal 7 database or preferably a dump
from which you would like to migrate from.

First take a dump of the database from the server you wish to migrate from.

```bash
mysqldump -uroot -p database -r database.dump
```

> N.B. Note that redirection is not used as that can affect the encoding of the
> output of the database dump if the locale in the terminal differs from the
> database.

Then import the dump of the database into a temporary database that you can
safely work with.

```bash
docker run --rm -ti -d --name tmp_database islandora/mariadb
sleep 10
docker exec -ti tmp_database mysql -e 'create database tmp'
docker cp database.dump tmp_database:/tmp
docker exec -ti tmp_database mysql --default-character-set=utf8mb4 tmp
```

You should be at the mysql client terminal prompt, enter the following commands:

```sql
SET names 'utf8';
SOURCE /tmp/database.dump;
```

> N.B. Note again we are not using redirection so that the appropriate encoding
> is reserved.

While still in the mysql client prompt, generate `user.csv` using the following
commands.

```sql
SELECT 'name', 'pass', 'mail', 'status', 'timezone', 'language'
UNION ALL
SELECT name,pass,mail,status,timezone,language
FROM users
WHERE uid > 1
INTO OUTFILE '/tmp/users.csv'
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n';
exit
```

Copy the generated `users.csv` file out of the temporary container.

```bash
docker cp tmp_database:/tmp/users.csv .
```

In your current directory there should now be a `users.csv` file. You can now
kill the temporary container.

```bash
docker rm -f tmp_database
```

Move the `users.csv` file to the path
`/var/www/drupal/web/modules/contrib/islandora_migrate_fedora_feature/artifacts/drupal/users.csv`
in the container where you expect to run the migration.

### Fedora Data

Generating content files and CSV that reside in `artifacts/fedora/` is handled
by the [migration] cli tool. Please refer to the documentation on that
repository for how those files are generated.

## Running the migrations

You can quickly run all migrations using `drush`:

```bash
drush -y mim --group fedora
```

If you want to go through the UI, you can visit `admin/structure/migrate` to see
a list of migration groups. The migrations provided by this module have the
machine name `fedora`.

The operations you can run for a migration are:

- **Import** - import un-migrated objects (check the "Update" checkbox to re-run
  previously migrated objects)
- **Rollback** - delete all the objects (if any) previously imported
- **Stop** - stop a long running import.
- **Reset** - reset an import that might have failed.

If you select "Import", and then click "Execute", it will run the migration.

## How this migration works

The [migration] cli can be used to extract both inline/managed datastreams and
Foxml from an existing Fedora 3 installation. These files should be placed into
the sites `public://fedora` directory, as thats where the CSV files will
reference them from.

Additionally the [migration] cli tool can generate a number of `CSV` files which
should be placed in the `artifacts/fedora` folder.

All datastreams are migrated over as-is with no modification.

All entities are derived from the Foxml / Fedora datastreams with the exception
of `users` which comes from the Drupal 7 site as described in
[Drupal Data](#drupal-data).

## Advanced Drush commands

Useful drush commands for working with the migration process.

### Import command

This starts the import process from the command line with the username and action specified.

```bash
$ drush --uri=http://localhost:8000 --userid=1 -y migrate:import --group fedora --update
        └─────────────────────────┘:└────────┘└─┘ └────────────┘ └────────────┘ └──────┘
URL of Islandora 8 ───┘                │       │        │                │             │
User Numeric ID  ──────────────────────┘       │        │                │             │
send yes to confirmation(optional) ────────────┘        │                │             │
Module and action ──────────────────────────────────────┘                │             │
Group name ──────────────────────────────────────────────────────────────┘             │
Update existing objects(optional) ─────────────────────────────────────────────────────┘
```

### Control the migration

```bash
$ drush migrate:rollback --update --limit="1000 items" --feedback="20 items" islandora_fedora_audit_media
        └─────┘:└──────┘ └──────┘└───────────────────┘ └───────────────────┘ └──────────────────────────┘
Module Name ┘      │         │           │                      │                          │
Action ────────────┘         │           │                      │                          │
confirmation(optional) ──────┘           │                      │                          │
Number of unprocessed items to run ──────┘                      │                          │
Number of items to display after completed ─────────────────────┘                          │
Migration Step ─────────────────────────────────────────────────────────────sourceRow───────────────┘
```

#### Rollbacks

Much of the content can be rolled back and deleted from the new site safety,
with the exceptions of revisions. The Drupal migration system does not support
the rolling back of revisions so they will remain in the site forever unless
manually deleted, which is fine assuming that you also roll back the entity that
the revisions point to, otherwise on subsequent migrations you may have more
revisions than intended.

Also to get the dates set appropriately on revisions you must run an update
migration on the revisions after the initial successful migration.

[migration]: https://github.com/nigelgbanks/migration
