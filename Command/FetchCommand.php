<?php
/**
 * This file is part of the data-transfer-bundle
 *
 * (c) Kuborgh GmbH
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace Kuborgh\DataTransferBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Command to fetch live data according to the configured parameters
 */
class FetchCommand extends AbstractCommand
{
    /**
     * Regex to check if the remote dump is ok
     * must start with '-- MySQL dump'
     */
    const VALID_DUMP_REGEX_1 = '/^\-\- MySQL dump/';

    /**
     * Regex to check if the remote dump is ok
     * must end with '-- Dump completed'
     */
    const VALID_DUMP_REGEX_2 = '/\-\- Dump completed on\s+\d*\-\d*\-\d*\s+\d+\:\d+\:\d+[\r\n\s\t]*$/';

    /**
     * Configure command
     */
    protected function configure()
    {
        $this
            ->setName('data-transfer:fetch')
            ->setDescription('Fetch remote database and files from configured system.')
            ->addOption('db-only', 'db-only', InputOption::VALUE_NONE, 'Only transfer the database, not the files.')
            ->addOption(
                'files-only',
                'files-only',
                InputOption::VALUE_NONE,
                'Only transfer the files, not the database.'
            );
    }

    /**
     * Execute the command
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input  Input
     * @param \Symfony\Component\Console\Output\OutputInterface $output Output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        // Fetch and import live database
        if (!$input->getOption('files-only')) {
            try {
                $this->fetchDatabase();
            } catch (\Exception $exc) {
                $this->progressErr($exc->getMessage());
            }
            $this->progressDone();
        }

        // fetch live data files
        if (!$input->getOption('db-only')) {
            try {
                $this->fetchFiles();
            } catch (\Exception $exc) {
                $this->progressErr($exc->getMessage());
            }
            $this->progressDone();
        }

    }

    /**
     * Log in to the remote server and dump the database.
     */
    protected function fetchDatabase()
    {
        $this->output->writeln('Fetching database');

        // Prepare remote command
        $remoteHost = $this->getParam('remote.host');
        $remoteUser = $this->getParam('remote.user');
        $remoteDir = $this->getParam('remote.dir');
        $remoteEnv = $this->getParam('remote.env');
        $consoleCmd = $this->getParam('console_script');
        $options = $this->getParam('ssh.options');

        // Check for ssh proxy
        $sshProxyString = $this->getSshProxyOption();
        if ($sshProxyString) {
            $options[] = $sshProxyString;
        }

        $exportCmd = sprintf(
            'ssh %s %s@%s "cd %s ; %s %s data-transfer:export 2>&1"',
            implode(' ', $options),
            $remoteUser,
            $remoteHost,
            $remoteDir,
            $consoleCmd,
            $remoteEnv ? '--env=' . $remoteEnv : ''
        );
        $this->progress();

        // Execute command
        $process = new Process($exportCmd);
        $process->setTimeout(null);
        $bytes = 0;
        // Update status for each megabyte
        $process->run(
            function ($type, $buffer) use (&$bytes) {
                $bytes += strlen($buffer);
                if ($bytes / 1024 / 1024 >= 1) {
                    $this->progress();
                    $bytes = 0;
                }
            }
        );

        // Check for error
        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf(
                'Cannot connect to remote host: %s %s',
                $process->getOutput(),
                $process->getErrorOutput()
            ));
        }
        $this->progressOk();

        // Check if we have a valid dump in our output
        // first line must start with '-- MySQL dump' and end with '-- Dump completed'
        $sqlDump = $process->getOutput();
        if (!preg_match(self::VALID_DUMP_REGEX_1, $sqlDump) || !preg_match(self::VALID_DUMP_REGEX_2, $sqlDump)) {
            throw new \Exception(sprintf('Error on remote host: %s', $process->getOutput()));
        }
        $this->progressOk();

        // Save dump to temporary file
        $tmpFile = $this->getContainer()->getParameter('kernel.cache_dir') . '/data-transfer.sql';
        file_put_contents($tmpFile, $sqlDump);
        $this->progressDone();

        // Import database
        $this->output->writeln('Importing database');

        // Fetch db connection data
        $siteaccess = $this->getContainer()->getParameter('data_transfer_bundle.siteaccess');

        $legacyParameter = sprintf('ezsettings.%s.database.params', $siteaccess);
        $repositoryParameter = sprintf('ezsettings.%s.repository', $siteaccess);
		
        if($this->getContainer()->hasParameter($legacyParameter)) {
            $dbParams = $this->getContainer()->getParameter($legacyParameter);
            $dbName = $dbParams['database'];
            $dbUser = $dbParams['user'];
            $dbPass = $dbParams['password'];
            $dbHost = $dbParams['host'];
        } elseif ($this->getContainer()->hasParameter($repositoryParameter)) {
            $repository = $this->getContainer()->getParameter($repositoryParameter);
            $repositories = $this->getContainer()->getParameter('ezpublish.repositories');
            $connection = $repositories[$repository]['connection'];
            /** @var $dbalConnection Connection */
            $dbalConnection = $this->getContainer()->get(sprintf('doctrine.dbal.%s_connection', $connection));

            $dbName = $dbalConnection->getDatabase();
            $dbUser = $dbalConnection->getUsername();
            $dbPass = $dbalConnection->getPassword();
            $dbHost = $dbalConnection->getHost();
        } else {
            $message = "Unable to find database settings from siteaccess. You need to define either %s or %s";
            throw new \Exception(sprintf($message, $legacyParameter, $repositoryParameter));
        }

        // Import Dump
        $importCmd = sprintf(
            'mysql %s --user=%s --password=%s --host=%s < %s 2>&1',
            escapeshellarg($dbName),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbHost),
            escapeshellarg($tmpFile)
        );
        $this->progress();

        $process = new Process($importCmd);
        $process->setTimeout(null);
        // Update status for each megabyte
        $process->run();
        $this->progress();

        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf(
                'Error importing database: %s %s',
                $process->getOutput(),
                $process->getErrorOutput()
            ));
        }
        $this->progressOk();

        // Remove temp dump file
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
        $this->progress();
    }

    /**
     * Fetch files from remote server
     */
    protected function fetchFiles()
    {
        $this->output->writeln('Fetching files');

        // Fetch folders to rsync
        $folders = $this->getParam('folders');
        $rsyncOptions = $this->getParam('rsync.options');
        $sshOptions = $this->getParam('ssh.options');

        // Check for ssh proxy
        $sshProxyString = $this->getSshProxyOption();
        if ($sshProxyString) {
            $sshOptions[] = $sshProxyString;
        }

        // Fetch params
        $remoteHost = $this->getParam('remote.host');
        $remoteUser = $this->getParam('remote.user');
        $remoteDir = $this->getParam('remote.dir');

        // Loop over the folders, to be transfered
        foreach ($folders as $src => $dst) {
            // If src = numeric, then indiced array was taken. detect folder automatically
            if (is_numeric($src)) {
                $src = $dst;
                $dst = dirname($src);
            }

            // Prepare command
            $cmd = sprintf(
                'rsync %s -e \'ssh %s\' %s@%s:%s/%s %s/ 2>&1',
                implode(' ', $rsyncOptions),
                implode(' ', $sshOptions),
                $remoteUser,
                $remoteHost,
                $remoteDir,
                $src,
                $dst
            );

            // Run (with callback to update those fancy dots
            $process = new Process($cmd);
            $process->setTimeout(null);
            $process->run(
                function () {
                    $this->progress();
                }
            );
            if (!$process->isSuccessful()) {
                throw new \Exception(sprintf(
                    'Error fetching files: %s %s',
                    $process->getOutput(),
                    $process->getErrorOutput()
                ));
            }

            $this->progressOk();
        }
    }

    /**
     * Fetch a parameter from config
     *
     * @param String $param Name of the parameter (without the ugly prefixes)
     *
     * @return mixed
     */
    protected function getParam($param)
    {
        return $this->getContainer()->getParameter('data_transfer_bundle.' . $param);
    }

    /**
     * Find ssh proxy options and return as ssh option string
     *
     * @return String
     */
    protected function getSshProxyOption()
    {
        // Check for ssh proxy
        $sshProxyHost = $this->getParam('ssh.proxy.host');
        $sshProxyUser = $this->getParam('ssh.proxy.user');
        $sshProxyOptions = $this->getParam('ssh.proxy.options');

        // No host or user -> no proxy
        if (!$sshProxyHost || !$sshProxyUser) {
            return '';
        }

        // Build option string
        $opt = sprintf(
            '-o ProxyCommand="ssh -W %%h:%%p %s %s@%s"',
            implode(' ', $sshProxyOptions),
            $sshProxyUser,
            $sshProxyHost
        );

        return $opt;
    }
} 