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

final class HHVMDaemon extends PHPEngine {
  private PerfTarget $target;

  public function __construct(
    private PerfOptions $options,
  ) {
    $this->target = $options->getTarget();
    parent::__construct((string) $options->hhvm);

    $output = [];
    $check_command = implode(
      ' ',
      (Vector {
          $options->hhvm,
          '-v', 'Eval.Jit=1',
          __DIR__.'/hhvm_config_check.php',
      })->map($x ==> escapeshellarg($x))
    );
    if ($options->traceSubProcess) {
      fprintf(STDERR, "%s\n", $check_command);
    }
    exec($check_command, $output);
    $checks = json_decode(implode("\n", $output), /* as array = */ true);
    invariant($checks, 'Got invalid output from hhvm_config_check.php');
    if (array_key_exists('HHVM_VERSION', $checks)) {
      $version = $checks['HHVM_VERSION'];
      if (version_compare($version, '3.4.0') === -1) {
        fprintf(
          STDERR,
          'WARNING: Unable to confirm HHVM is built correctly. This is '.
          'supported in 3.4.0-dev or later - detected %s. Please make sure '.
          'that your HHVM build is a release build, and is built against '.
          "libpcre with JIT support.\n",
          $version
        );
        sleep(2);
        return;
      }
    }
    BuildChecker::Check(
      $options,
      (string) $options->hhvm,
      $checks,
      Set { 'HHVM_VERSION' },
    );
  }
  
  protected function getTarget(): PerfTarget {
    return $this->target;
  }

  <<__Override>>
  protected function getArguments(): Vector<string> {
    $args = Vector {
      '-m', 'server',
      '-p', (string) PerfSettings::FastCGIPort(),
      '-v', 'Server.Type=fastcgi',
      '-v', 'Eval.Jit=1',
      '-v', 'AdminServer.Port='.PerfSettings::FastCGIAdminPort(),
      '-c', OSS_PERFORMANCE_ROOT.'/conf/php.ini',
    };
    if (count($this->options->hhvmExtraArguments) > 0) {
      $args->addAll($this->options->hhvmExtraArguments);
    }
    return $args;
  }

  <<__Override>>
  public function start(): void {
    parent::startWorker(
      $this->options->daemonOutputFileName('hhvm'),
      $this->options->delayProcessLaunch,
      $this->options->traceSubProcess,
    );
    invariant($this->isRunning(), 'Failed to start HHVM');
    for ($i = 0; $i < 10; ++$i) {
      Process::sleepSeconds($this->options->delayCheckHealth);
      $health = $this->adminRequest('/check-health', true);
      if ($health) {
        if ($health === "failure") {
          continue;
        }
        $health = json_decode($health, /* assoc array = */ true);
        if (array_key_exists('tc-size', $health) && $health['tc-size'] > 0) {
          return;
        }
      }
      $this->stop();
      return;
    }
  }

  public function stop(): void {
    try {
      $health = $this->adminRequest('/check-health');
      if ($health && json_decode($health)) {
        $this->adminRequest('/stop');
        invariant(!$this->isRunning(), 'Failed to stop HHVM');
      }
    } catch (Exception $e) {
      parent::stop();
    }
  }

  protected function adminRequest(
    string $path,
    bool $allowFailures = true
  ): string {
    $url = 'http://localhost:'.PerfSettings::HttpAdminPort().$path;
    $ctx = stream_context_create(
      ['http' => ['timeout' => $this->options->maxdelayAdminRequest]]
    );
    //
    // TODO: it would be nice to suppress
    // Warning messages from file_get_contents
    // in the event that the connection can't even be made.
    //
    $result = file_get_contents(
      $url,
      /* include path = */ false,
      $ctx);
    if ($result !== false) {
      return $result;
    }
    if ($allowFailures) {
      return "failure";
    } else {
      invariant($result !== false, 'Admin request failed');
      return $result;
    }
  }

  public function __toString(): string {
    return (string) $this->options->hhvm;
  }
}
