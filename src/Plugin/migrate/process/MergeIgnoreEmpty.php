<?php

// Original source: https://github.com/Islandora-Devops/migrate_7x_claw/blob/052e2ccf4d0670aed04ac494fef19248d2ef6a25/src/Plugin/migrate/process/MergeIgnoreEmpty.php
namespace Drupal\islandora_migrate_fedora_feature\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Plugin\migrate\process\Merge;

/**
 * This plugin merges arrays together, ignoring empty values.
 *
 * @see \Drupal\migrate_plus\Plugin\migrate\process\Merge
 *
 * @MigrateProcessPlugin(
 *   id = "merge_ignore_empty"
 * )
 */
class MergeIgnoreEmpty extends Merge {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Trick the merge with two empty arrays if the value isn't there at all.
    if (empty($value)) {
      $value = [[],[]];
    }
    else {
      // Trick the merge with empty arrays when some 'source' don't have values.
      for ($i = 0; $i < count($value); $i++) {
        if (empty($value[$i])) {
          $value[$i] = [];
        }
      }
    }
    // Do a regular merge, which now shouldn't throw a MigrateException.
    return parent::transform($value, $migrate_executable, $row, $destination_property);
  }

}
