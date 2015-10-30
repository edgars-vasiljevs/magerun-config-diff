<?php
namespace ConfigDiff;

require __DIR__ . '/../../vendor/autoload.php';

use Mage;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Question\Question;

class DiffCommand extends AbstractMagentoCommand
{
    /**
     * Default SSH port
     */
    const DEFAULT_PORT = 22;

    /**
     * Default column width
     */
    const DEFAULT_COLUMN_WIDTH = 50;

    /**
     * @var InputInterface
     */
    protected $_input;

    /**
     * @var OutputInterface
     */
    protected $_output;

    /**
     * @var QuestionHelper
     */
    protected $_questionHelper;

    /** @var null|string
     */
    protected $_path = null;

    /**
     * @var null
     */
    protected $_exec = null;

    protected function configure()
    {
        $this->setName('scandi:config-diff')
            ->setDescription('Compare configuration between local and remote box via SSH')
            ->addArgument('remote', InputArgument::REQUIRED, 'user@machine:/path/to/magento/root')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Set SSH password', NULL)
            ->addOption('column-width', null, InputOption::VALUE_OPTIONAL, 'Max column width in output', self::DEFAULT_COLUMN_WIDTH);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;
        $this->_questionHelper = $this->getHelper('question');

        $this->detectMagento($output);

        if (!$this->initMagento()) {
            return;
        }

        $this->_connect();
    }

    /**
     * Connect to remote box
     */
    protected function _connect()
    {
        $remote = $this->_input->getArgument('remote');

        if (!preg_match('#^([^@]+)@([a-z0-9\.\-]+)(:\d+|):(.+)$#i', $remote, $match)) {
            throw new \Exception("Invalid remote host! Use user@machine:/path/to/magento/root");
        }

        list(, $username, $hostname, $port, $this->_path) = $match;

        if (!$port) {
            $port = self::DEFAULT_PORT;
        }

        // Ask for password if not set as option
        if (!$this->_input->getOption('password')) {
            $password = $this->_askPassword();
        }
        else {
            $password = $this->_input->getOption('password');
        }

        $configuration = new \Ssh\Configuration($hostname, (int)$port);
        $authentication = new \Ssh\Authentication\Password($username, $password);

        $session = new \Ssh\Session($configuration, $authentication);

        $this->_exec = $session->getExec();

        $this->_diff();
    }

    /**
     * Prompt password
     *
     * @return string
     */
    protected function _askPassword()
    {
        $question = new Question(sprintf('Password: '));
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        return trim($this->_questionHelper->ask($this->_input, $this->_output, $question));
    }

    /**
     * Diff configs
     */
    protected function _diff()
    {
        $remoteConfig = $this->_retrieveRemoteConfig();
        $localConfig = $this->_retrieveLocalConfig();

        foreach($localConfig as $scope => $localConfigList) {

            // Print scope
            $this->_getScopeHead($scope);

            $table = new Table($this->_output);
            $table->setHeaders(array('Path', 'Local', 'Remote'));

            $diff = array();
            foreach($localConfigList as $path => $value) {

                if (!isset($remoteConfig[$scope][$path])) {
                    continue;
                }

                // If identical, skip
                if (array_key_exists($path, $remoteConfig[$scope]) && $remoteConfig[$scope][$path] === $value) {
                    continue;
                }

                if (!array_key_exists($path, $remoteConfig[$scope])) {
                    $diff[] = array(
                        $this->_outputString($path),
                        $this->_outputString($value),
                        $this->_missing()
                    );
                }
                else {
                    $diff[] = array(
                        $this->_outputString($path),
                        $this->_outputString($value),
                        $this->_outputString($remoteConfig[$scope][$path]),
                    );
                }
                $diff[] = new TableSeparator();
            }

            foreach($remoteConfig[$scope] as $path => $value) {
                if (!array_key_exists($path, $localConfigList)) {
                    $diff[] = array(
                        $this->_outputString($path),
                        $this->_missing(),
                        $this->_outputString($value),
                    );
                    $diff[] = new TableSeparator();
                }
            }

            array_pop($diff);

            if (empty($diff)) {
                $this->_output->writeln('Configs are identical!');
            }
            else {
                $table->setRows($diff);
                $table->render();
            }
        }
    }

    protected function _getScopeHead($scope)
    {
        if ($scope == 'default_0') {
            $response = 'Default configuration';
        }
        else {
            list($scope, $scopeId) = explode('_', $scope);

            if ($scope == 'websites') {
                $response = 'Website #' . $scopeId;
            }
            else if ($scope == 'stores') {
                $response = 'Store #' . $scopeId;
            }
        }

        $this->_output->writeln("");
        $this->_output->writeln($response);
    }

    /**
     * Returns "MISSING" in red color
     *
     * @return string
     */
    protected function _missing()
    {
        return "\033[0;31mMISSING\033[0m";
    }

    /**
     * Escape and limit string to specific width
     *
     * @param string $string
     * @return string
     */
    protected function _outputString($string)
    {
        $string = str_replace("\r", "\n", $string);
        return wordwrap(trim($string), $this->_input->getOption('column-width'), "\n", true);
    }

    /**
     * Retrieve remote config by executing php code on remote box
     *
     * @return array
     */
    protected function _retrieveRemoteConfig()
    {
        $phpCode = preg_replace('/\s+/', ' ', '
            require_once "app/Mage.php"; Mage::app();
            $data = array();
            foreach(Mage::getModel("core/config_data")->getCollection()->setOrder("path", "ASC") as $c) {
                $data[$c->getScope()."_".$c->getScopeId()][$c->getPath()] = $c->getValue();
            }
            echo serialize($data);
        ');

        $commands = sprintf("cd '%s' \n php -r '%s'", $this->_path, $phpCode);
        $response = $this->_exec->run($commands);

        return unserialize($response);
    }

    /**
     * Retrieve local config from current magento project
     *
     * @return array
     */
    protected function _retrieveLocalConfig()
    {
        $configs = Mage::getModel("core/config_data")->getCollection()->setOrder("path", "ASC");

        $data = array();
        foreach($configs as $config) {
            $data[$config->getScope()."_".$config->getScopeId()][$config->getPath()] = $config->getValue();
        }

        return $data;
    }
}