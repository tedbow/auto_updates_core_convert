<?php

namespace Tedbow\AutoUpdatesConvert;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * A class to do all the things.
 */
class TheClass {
  public static function getSetting(string $key) {
    return static::getSettings()[$key];
  }

  /**
   * @param $core_dir
   *
   * @return string
   */
  public static function getCoreModulePath(): string {
    return TheClass::getSetting('core_dir') . '/core/modules/auto_updates';
  }

  /**
   * Replaces a string in the contents of the module files.
   *
   * @param string $search
   * @param string $replace
   */
  public static function replaceContents(string $search, string $replace) {
    $files = static::getDirContents(static::getCoreModulePath(), TRUE);
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
  public static function renameFiles(string $old_pattern, string $new_pattern) {

    $files = static::getDirContents(static::getCoreModulePath());

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
  public static function getDirContents(string $path, $excludeDirs = FALSE): array {
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

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
  protected static function getSettings() {
    static $settings;
    if (!$settings) {
      $settings = Yaml::parseFile(__DIR__ . '/../config.yml');
      $settings_keys = array_keys($settings);
      $require_settings = ['core_mr_branch', 'contrib_dir', 'core_dir'];
      $missing_settings = array_diff($require_settings, $settings_keys);
      if ($missing_settings) {
        throw new \Exception('Missing settings: ' . print_r($missing_settings,
            TRUE));
      }
    }

    return $settings;
  }

  /**
   * Ensures the git status is clean.
   *
   * @return bool
   * @throws \Exception
   */
  public static function ensureGitClean() {
    $status_output = shell_exec('git status');
    if (strpos($status_output, 'nothing to commit, working tree clean') === FALSE) {
      throw new \Exception("git not clean: " .$status_output);
    }
    return TRUE;
  }

  /**
   * Gets the current git branch.
   *
   * @return string
   */
  public static function getCurrentBranch() {
    return trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
  }

  /**
   * Switches to the branches we need.
   *
   * @throws \Exception
   */
  public static function switchToBranches() {
    $settings = static::getSettings();
    chdir($settings['contrib_dir']);
    static::switchToBranch('8.x-2.x');
    chdir($settings['core_dir']);
    static::switchToBranch($settings['core_mr_branch']);
  }

  /**
   * Switches to a branches and makes sure it is clean.
   *
   * @param string $branch
   *
   * @throws \Exception
   */
  public static function switchToBranch(string $branch) {
    static::ensureGitClean();
    shell_exec("git checkout $branch");
    if ($branch !== static::getCurrentBranch()) {
      throw new \Exception("could not check $branch");
    }
  }

  public static function makeCommit() {
    chdir(self::getSetting('contrib_dir'));
    self::ensureGitClean();
    $hash = trim(shell_exec('git rev-parse HEAD'));
    chdir(self::getSetting('core_dir'));
    shell_exec('git add core');
    shell_exec("git commit -m 'Update to commit from contrib 8.x-2.x https://git.drupalcode.org/project/automatic_updates/-/commit/$hash'");
  }

  public static function addWordsToDictionary(array $new_words) {
    $dict_file = self::getSetting('core_dir') . '/core/misc/cspell/dictionary.txt';
    $contents = file_get_contents($dict_file);
    $words = explode("\n", $contents);
    $words = array_filter($words);
    foreach ($new_words as $new_word) {
      if (array_search($new_word, $words)) {
        continue;
      }
      $words[] = $new_word;
    }
    asort($words);
    file_put_contents($dict_file, implode("\n", $words));

  }

  public static function runCoreChecks() {
    chdir(self::getSetting('core_dir'));
    $output = NULL;
    $result = NULL;
    system(' sh ./core/scripts/dev/commit-code-check.sh --branch 9.4.x', $result);
    if ($result !== 0) {
      print "😭commit-code-check.sh failed";
      exit(1);
    }
    print "🎉 commit-code-check.sh passed!";
  }

}
