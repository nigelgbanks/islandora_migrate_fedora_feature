<?php

/**
 * Allows parallel execution of migrates, though each migration is still run serially.
 * 
 * Derived from the following issue/patch:
 *  - https://www.drupal.org/project/migrate_tools/issues/3114358
 *  - https://www.drupal.org/files/issues/2020-02-18/ImportParallel.php_.txt
 */

namespace Drupal\islandora_migrate_fedora_feature\Commands;

use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate\Plugin\RequirementsInterface;
use Drush\Drush;
use Drush\Commands\DrushCommands;

/**
 * Drush command for graphing migration dependencies.
 */
class ImportParallelCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use SiteAliasManagerAwareTrait;

  /**
   * Migration plugin manager service.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * List of migrations not yet run.
   *
   * @var array[]
   *   Keys are migration IDs; values are arrays of dependencies.
   */
  protected $migrations = [];

  /**
   * List of currently running migration processes.
   *
   * @var \Consolidation\SiteProcess\SiteProcess[]
   */
  protected $processes = [];

  /**
   * Maximum number of migrations to run simultaneously (0 for unlimited).
   *
   * @var int
   */
  protected $max_processes = 0;

  /**
   * How many minutes between status updates (0 for no updates);
   *
   * @var int
   */
  protected $status_frequency = 0;

  /**
   * ImportParallel constructor.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManager $migrationPluginManager
   *   Migration Plugin Manager service.
   */
  public function __construct(MigrationPluginManager $migrationPluginManager) {
    parent::__construct();
    $this->migrationPluginManager = $migrationPluginManager;
  }

  /**
   * Run all migrations with maximum parallelism.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command migrate:import-parallel
   *
   * @option max-processes Maximum number of processes to run at once.
   * @option status-frequency Number of minutes between status updates.
   *
   * @default $options []
   *
   * @usage migrate:import-parallel --max-processes=5 --status-frequency=5
   *   Runs up to 5 migrations in parallel as soon as their dependencies are
   *   fulfilled, with status of running migrations output every 5 minutes.
   *
   * @aliases mimp
   */
  public function importParallel(array $options = [
    'max-processes' => self::OPT,
    'status-frequency' => self::OPT,
  ]) {
    $this->max_processes = $options['max-processes'] ?? 0;
    $this->status_frequency = $options['status-frequency'] ?? 0;
    // Gather all migrations and their dependencies.
    foreach ($this->migrationsList() as $migration_id => $migration) {
      $all_dependencies = $migration->getMigrationDependencies();
      $this->migrations[$migration_id] = $all_dependencies['required'] + $all_dependencies['optional'];
      // Make sure there are no self-references.
      if (($key = array_search($migration_id, $this->migrations[$migration_id])) !== false) {
        unset($this->migrations[$migration_id][$key]);
      }
    }
    // Start the initial batch.
    $this->executeReady();
    // As migration processes complete, update dependencies and start any others
    // now free to run.
    $lasttime = time();
    while (!empty($this->processes)) {
      sleep(1);
      foreach ($this->processes as $running_migration_id => $process) {
        $this->output()->write($process->getIncrementalErrorOutput());
        $this->output()->write($process->getIncrementalOutput());
        if ($process->isTerminated()) {
          unset($this->processes[$running_migration_id]);
          foreach ($this->migrations as $migration_id => $dependencies) {
            foreach ($dependencies as $index => $dependency) {
              if ($running_migration_id == $dependency) {
                unset($this->migrations[$migration_id][$index]);
              }
            }
          }
        }
      }
      $this->executeReady();
      // Do a migrate:status on currently running migrations if requested.
      if ($this->status_frequency && ((($currenttime = time()) - $lasttime) / 60) >= $this->status_frequency) {
        $lasttime = $currenttime;
        $status_process = Drush::drush($this->siteAliasManager()->getSelf(), 'migrate:status',
          [implode(',', array_keys($this->processes))], ['fields' => 'id,status,total,imported,unprocessed']);
        $status_process->start();
        $status_process->wait();
        $this->output()->write($status_process->getOutput());
      }
    }
    if (!empty($this->migrations)) {
      $this->output()->write("The following migrations did not complete:\n" .
        print_r($this->migrations, TRUE));
    }
  }

  /**
   * Execute migrations with no remaining dependencies in subprocesses.
   */
  protected function executeReady() {
    $self_record = $this->siteAliasManager()->getSelf();
    foreach ($this->migrations as $migration_id => $dependencies) {
      if (empty($dependencies)) {
        if ($this->max_processes == 0 || count($this->processes) < $this->max_processes) {
          unset($this->migrations[$migration_id]);
          $this->output()->write("Starting $migration_id\n");
          $process = Drush::drush($self_record, 'migrate:import',
            [$migration_id], ['skip-progress-bar' => TRUE, 'continue-on-failure' => TRUE]);
          $this->processes[$migration_id] = $process;
          $process->start();
          // Too many processes too fast risks deadlocks.
          sleep(3);
        }
      }
    }
  }

  /**
   * Retrieve a list of all active migrations.
   *
   * @return \Drupal\migrate\Plugin\MigrationInterface[]
   *   An array of migrations keyed by migration ID.
   */
  protected function migrationsList() {
    $manager = $this->migrationPluginManager;
    $migrations = $manager->createInstances([]);

    // Do not return any migrations which fail to meet requirements.
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    foreach ($migrations as $id => $migration) {
      try {
        if ($migration->getSourcePlugin() instanceof RequirementsInterface) {
          $migration->getSourcePlugin()->checkRequirements();
        }
      }
      catch (RequirementsException $e) {
        unset($migrations[$id]);
      }
    }

    return $migrations ?? [];
  }

}
