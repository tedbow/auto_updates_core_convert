<?php
require_once "vendor/autoload.php";
// We shouldn't get any warnings so lets make sure we throw exceptions.
set_error_handler(function ($severity, $message, $file, $line) {
  throw new \ErrorException($message, $severity, $severity, $file, $line);
});

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

$old_machine_name = 'automatic_updates';
$new_machine_name = 'auto_updates';
$settings = getSettings();
function getSetting(string $key) {
  return getSettings()[$key];
}

/**
 * Replaces a string in the contents of the module files.
 *
 * @param string $search
 * @param string $replace
 */
function replaceContents(string $search, string $replace) {
  $files = getDirContents(getCoreModulePath(), TRUE);
  foreach ($files as $file) {
    $filePath = $file->getRealPath();
    file_put_contents($filePath,str_replace($search,$replace,file_get_contents($filePath)));
  }

}

/**
 * Renames the module files.
 *
 * @param string $old_pattern
 * @param string $new_pattern
 */
function renameFiles(string $old_pattern, string $new_pattern) {

  $files = getDirContents(getCoreModulePath());

  // Keep a record of the files and directories to change.
  // We will change all the files first so we don't change the location of any
  // of the files in the middle.
  // This probably won't work if we had nested folders with the pattern on 2
  // folder levels but we don't.
  $filesToChange = [];
  $dirsToChange = [];
  foreach ($files as $file) {
    $fileName = $file->getFilename();
    if ($fileName === '.') {
      $fullPath = $file->getPath();
      $parts = explode('/', $fullPath);
      $name = array_pop($parts);
      $path = "/" . implode('/', $parts);
    }
    else {
      $name = $fileName;
      $path = $file->getPath();
    }
    if (strpos($name, $old_pattern) !== FALSE) {
      $new_filename = str_replace($old_pattern, $new_pattern, $name);
      if ($file->isFile()) {
        $filesToChange[$file->getRealPath()] = $file->getPath() . "/$new_filename";
      }
      else {
        $dirsToChange[$file->getRealPath()] = "$path/$new_filename";
      }
    }
  }
  foreach ($filesToChange as $old => $new) {
    (new Filesystem())->rename($old, $new);
  }

  foreach ($dirsToChange as $old => $new) {
    (new Filesystem())->rename($old, $new);
  }

}

/**
 * Gets the contents of a directory.
 *
 * @param string $path
 * @param bool $excludeDirs
 *
 * @return \SplFileInfo[]
 */
function getDirContents(string $path, $excludeDirs = FALSE): array {
  $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

  $files = array();
  /** @var \SplFileInfo $file */
  foreach ($rii as $file) {
    if($excludeDirs && $file->isDir()) {
      continue;
    }
    $files[] = $file;
  }

  return $files;
}

/**
 * Gets the settings from config.yml.
 *
 * @return array
 * @throws \Exception
 */
function getSettings() {
  $settings = Yaml::parseFile(__DIR__ . '/config.yml');
  $settings_keys = array_keys($settings);
  $require_settings = ['core_mr_branch', 'contrib_dir', 'core_dir'];
  $missing_settings = array_diff($require_settings, $settings_keys);
  if ($missing_settings) {
    throw new Exception('Missing settings: ' . print_r($missing_settings,
        TRUE));
  }
  return $settings;
}

/**
 * Ensures the git status is clean.
 *
 * @return bool
 * @throws \Exception
 */
function ensureGitClean() {
  $status_output = shell_exec('git status');
  if (strpos($status_output, 'nothing to commit, working tree clean') === FALSE) {
    throw new Exception("git not clean: " .$status_output);
  }
  return TRUE;
}

/**
 * Gets the current git branch.
 *
 * @return string
 */
function getCurrentBranch() {
  return trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
}

/**
 * Switches to the branches we need.
 *
 * @throws \Exception
 */
function switchToBranches() {
  $settings = getSettings();
  chdir($settings['contrib_dir']);
  switchToBranch('8.x-2.x');
  chdir($settings['core_dir']);
  switchToBranch($settings['core_mr_branch']);
}

/**
 * Switches to a branches and makes sure it is clean.
 *
 * @param string $branch
 *
 * @throws \Exception
 */
function switchToBranch(string $branch) {
  ensureGitClean();
  shell_exec("git checkout $branch");
  if ($branch !== getCurrentBranch()) {
    throw new Exception("could not check $branch");
  }
}


switchToBranches();
$fs = new Filesystem();
/**
 * @param $core_dir
 *
 * @return string
 */
function getCoreModulePath(): string {
  return getSetting('core_dir') . '/core/modules/auto_updates';
}

$core_module_path = getCoreModulePath();
// Remove old module
$fs->remove($core_module_path);
// Copy the contrib module to core.
$fs->mirror($settings['contrib_dir'], $core_module_path );

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
  renameFiles($search, $replace);
  replaceContents($search, $replace);
}


