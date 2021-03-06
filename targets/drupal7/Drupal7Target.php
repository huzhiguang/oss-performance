<?hh
/*
 *  Copyright (c) 2014, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

final class Drupal7Target extends PerfTarget {
  public function __construct(
    private PerfOptions $options,
  ) {
  }

  protected function getSanityCheckString(): string {
    return 'Read more';
  }

  public function install(): void {
    Utils::ExtractTar(
      __DIR__.'/drupal-7.31.tar.gz',
      $this->options->tempDir,
    );

    Utils::ExtractTar(
      __DIR__.'/demo-static.tar.bz2',
      $this->getSourceRoot().'/sites/default',
    );

    copy(
      'compress.zlib://'.__DIR__.'/settings.php.gz',
      $this->getSourceRoot().'/sites/default/settings.php',
    );

    (new DatabaseInstaller($this->options))
      ->setDatabaseName('drupal_bench')
      ->setDumpFile(__DIR__.'/dbdump.sql.gz')
      ->installDatabase();
  }

  public function getSourceRoot(): string {
    return $this->options->tempDir.'/drupal-7.31';
  }
}
