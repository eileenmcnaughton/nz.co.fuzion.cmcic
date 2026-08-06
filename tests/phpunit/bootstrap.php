<?php

declare(strict_types=1);

namespace {
  ini_set('memory_limit', '2G');

  if (getenv('CMCIC_UNIT_ONLY') !== '1') {
    $bootLevel = getenv('CMCIC_BOOT_LEVEL') ?: 'classloader';
    // phpcs:disable
    eval(cmcic_cv('php:boot --level=' . $bootLevel, 'phpcode'));
    // phpcs:enable
  }

  $loader = new \Composer\Autoload\ClassLoader();
  $loader->add('CRM_', [__DIR__ . '/../..', __DIR__]);
  $loader->addPsr4('Civi\\', [__DIR__ . '/../../Civi', __DIR__ . '/Civi']);
  $loader->register();

  if (!class_exists('CRM_Core_Payment')) {
    abstract class CRM_Core_Payment {
      protected $_paymentProcessor = [];
      public function getPaymentProcessor() { return $this->_paymentProcessor; }
    }
  }
  if (!class_exists('CRM_Core_Exception')) {
    class CRM_Core_Exception extends \Exception {}
  }
  if (!class_exists('CRM_Utils_System')) {
    class CRM_Utils_System {
      public static function version() { return '6.14.0'; }
    }
  }
  if (!function_exists('ts')) {
    function ts(string $str, array $args = []): string { return $str; }
  }

  /**
   * Execute a cv command in the extension directory.
   *
   * @param string $command
   * @param string $decode
   *
   * @return mixed
   */
  function cmcic_cv(string $command, string $decode = 'json') {
    $command = 'cv ' . $command;
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
    $previousOutput = getenv('CV_OUTPUT');
    putenv('CV_OUTPUT=json');
    $command = sprintf('cd %s; %s', escapeshellarg(getenv('PWD')), $command);

    $process = proc_open($command, $descriptorSpec, $pipes, __DIR__);
    putenv("CV_OUTPUT=$previousOutput");
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
      throw new RuntimeException("Command failed ($command):\n$result");
    }

    if ($decode === 'phpcode') {
      $trimmedResult = trim($result);
      if (!str_starts_with($trimmedResult, '/*BEGINPHP*/') || !str_ends_with($trimmedResult, '/*ENDPHP*/')) {
        throw new RuntimeException("Command failed ($command):\n$result");
      }
      return $result;
    }
    if ($decode === 'raw') {
      return $result;
    }
    if ($decode === 'json') {
      return json_decode($result, TRUE);
    }
    throw new RuntimeException('Unknown decoder format: ' . $decode);
  }
}

namespace Civi\Payment {
  if (!class_exists('Civi\Payment\System')) {
    class System {
      private static $instance = NULL;
      public static function singleton() {
        if (!self::$instance) { self::$instance = new System(); }
        return self::$instance;
      }
      public function getByName($name, $isTest) { return NULL; }
    }
  }
}

namespace Civi\Api4\Generic {
  if (!class_exists('Civi\Api4\Generic\AbstractAction')) {
    abstract class AbstractAction {}
  }
}
