<?php

namespace HBM\AsyncWorkerBundle\Command\Execution;

use HBM\AsyncWorkerBundle\AsyncWorker\Runner\Runner;
use HBM\AsyncWorkerBundle\Service\Informer;
use HBM\AsyncWorkerBundle\Service\Messenger;
use HBM\AsyncWorkerBundle\Service\ConsoleLogger;
use LongRunning\Core\Cleaner;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ListenCommand extends AbstractExecutionCommand {

  /**
   * @var string
   */
  public const NAME = 'hbm:async-worker:listen';

  /**
   * @var Cleaner
   */
  private $cleaner;

  /**
   * ListenCommand constructor.
   *
   * @param array $config
   * @param Messenger $messenger
   * @param Informer $informer
   * @param Cleaner $cleaner
   * @param ConsoleLogger $consoleLogger
   */
  public function __construct(array $config, Messenger $messenger, Informer $informer, Cleaner $cleaner, ConsoleLogger $consoleLogger) {
    parent::__construct($config, $messenger, $informer, $consoleLogger);

    $this->cleaner = $cleaner;
  }

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName(self::NAME)
      ->addArgument('runner', InputArgument::REQUIRED, 'The ID of the runner. Could be any integer/string. Just to identify this runner.')
      ->addOption('passthru', NULL, InputOption::VALUE_NONE, 'If this option is provided, the output of the executed jobs is passed thru to the console.')
      ->setDescription('Make the runner listening for jobs.');

      $this->configureCommand($this);
  }

  /**
   * @inheritdoc
   */
  protected function executeLogic(InputInterface $input, OutputInterface $output) : void {
    /**************************************************************************/
    /* CHECK REDIS                                                            */
    /**************************************************************************/
    if (!$this->messenger->isAvailable()) {
      $this->outputAndOrLog('Redis is not available.', 'critical');
      return;
    }

    /**************************************************************************/
    /* CHECK IF RUNNER IS TIMED OUT                                           */
    /**************************************************************************/
    if ($this->getRunner()->isTimedOut()) {
      if ($this->config['runner']['autorecover']) {
        $this->outputAndOrLog('Autorecover runner after timeout.', 'notice');
        $this->messenger->updateRunner($this->getRunner()->incrAutorecover()->reset());
      } else {
        $this->outputAndOrLog('Runner has timed out.', 'alert');
        $this->messenger->updateRunner($this->getRunner()->incrTimeouts()->setState(Runner::STATE_TIMEOUT));
        return;
      }
    }

    /**************************************************************************/
    /* CHECK IF RUNNER ALREADY LISTENING                                      */
    /**************************************************************************/
    if ($this->getRunner()->isListening()) {
      $this->outputAndOrLog('Runner is already listening.', 'debug');
      return;
    }

    /**************************************************************************/
    /* CHECK IF RUNNER HAS RECEIVED SHUTDOWN SIGNAL                           */
    /**************************************************************************/
    if ($this->hasRunnerReceivedShutdownSignal()) {
      return;
    }

    /**************************************************************************/
    /* START RUNNER                                                           */
    /**************************************************************************/
    /*
     * We'll set our base time, which is one hour (in seconds).
     * Once we have our base time, we'll add anywhere between 0
     * to 10 minutes randomly, so all runners won't stop at the
     * same time.
     */
    $timeLimit = $this->config['runner']['runtime']; // Minimum running time
    $timeLimit += random_int(0, $this->config['runner']['fuzz']); // Adding additional time

    $timeStart = time();

    $this->messenger->updateRunner($this->getRunner()
      ->setRunTimeout(new \DateTime('+'.round($this->config['runner']['timeout'] * $timeLimit).'sec'))
      // Set the last time this runner checked in, use this to help determine when scripts die.
      ->setRunStarted(new \DateTime('@'.$timeStart))
      ->setRunPid(getmypid())
      ->incrStarts()
      ->setState(Runner::STATE_LISTENING)
    );
    $this->outputAndOrLog(['LOG' => 'Runner started!', 'POSTFIX' => ' Listening for jobs...'], 'notice');

    /**************************************************************************/
    /* POLLING                                                                */
    /**************************************************************************/
    while (time() < $timeStart + $timeLimit) {
      // Execute queued job (if there is any).
      $executed = $this->executeOne($output);

      // Clean up (doctrine_orm, doctrine_dbal, monolog, swift_mailer spool).
      if ($executed) {
        $this->cleaner->cleanUp();
      }

      // Check if runner has received shutdown signal.
      if ($this->hasRunnerReceivedShutdownSignal()) {
        return;
      }

      // Enqueue delayed jobs, discard expired jobs
      $this->messenger->updateQueues();
    }

    // Reset runner.
    $this->messenger->updateRunner($this->getRunner()->incrStops()->reset());
    $this->outputAndOrLog(['LOG' => 'Planned shutdown!', 'POSTFIX' => ' Waiting for restart...'], 'notice');
  }

  /**
   * Check if runner received shutdown signal.
   *
   * @return bool
   */
  private function hasRunnerReceivedShutdownSignal() : bool {
    if ($this->getRunner()->getRunShutdown()) {
      $this->outputAndOrLog(['LOG' => 'Shutdown request detected.', 'POSTFIX' => ' Shutting down...'], 'notice');
      $this->messenger->updateRunner($this->getRunner()->incrShutdowns()->reset());

      return TRUE;
    }

    return FALSE;
  }

}
