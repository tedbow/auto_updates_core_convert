<?php
require_once "vendor/autoload.php";
// We shouldn't get any warnings so lets make sure we throw exceptions.
set_error_handler(function ($severity, $message, $file, $line) {
  throw new \ErrorException($message, $severity, $severity, $file, $line);
});

use Symfony\Component\Filesystem\Filesystem;
use Tedbow\AutoUpdatesConvert\TheClass;

$old_machine_name = 'automatic_updates';
$new_machine_name = 'auto_updates';

TheClass::switchToBranches();
$fs = new Filesystem();


$core_module_path = TheClass::getCoreModulePath();
// Remove old module
$fs->remove($core_module_path);
// Copy the contrib module to core.
$fs->mirror(TheClass::getSetting('contrib_dir'), $core_module_path );

// Remove unneeded
$removals = [
  'automatic_updates_9_3_shim',
  'drupalci.yml',
  'README.md'
];
$removals = array_map(function ($path) use ($core_module_path) { return "$core_module_path/$path"; }, $removals);
$fs->remove($removals);


// Replace in file names and contents.
$replacements = [
  $old_machine_name => $new_machine_name,
  'AutomaticUpdates' => 'AutoUpdates',
];
foreach ($replacements as $search => $replace) {
  TheClass::renameFiles($search, $replace);
  TheClass::replaceContents($search, $replace);
}


