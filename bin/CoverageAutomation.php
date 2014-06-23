<?php

$config  = json_decode(file_get_contents(__DIR__ . '/CoverageAutomation.json'));
$phpunit = new Phar($config->phpunit->phar, 0);
$matches = [];

preg_match_all('/[\"\']([^\"\']+)[\"\']\s*=>\s*[\"\']([^\"\']+)[\"\']/', $phpunit->getStub(), $matches);
$classes = array_combine($matches[1], $matches[2]);

spl_autoload_register(
    function ($class) use ($classes, $config)
    {
        $class = str_replace('\\', '\\\\', strtolower($class));
        if (isset($classes[$class])) {
            require 'phar://' . $config->phpunit->phar . $classes[$class];
        }
    }
);

class CoverageAutomation
{
    protected $config;

    protected $branch;

    protected $phpFile;

    protected $xmlFile;

    /** @var  PHP_CodeCoverage */
    protected $changedCoverage;

    /** @var  PHP_CodeCoverage */
    protected $branchCoverage;

    protected $deletions = [];

    protected $insertions = [];

    protected $testsToRun = [];

    public function __construct($config)
    {
        $coverage = $config->coverage;

        chdir($config->git->root);
        $branch  = trim(shell_exec($config->git->cmd . ' rev-parse --abbrev-ref HEAD'));
        $phpFile = str_replace('{branch}', $branch, $coverage->baseDir . '/' . $coverage->phpFile);
        $xmlFile = str_replace('{branch}', $branch, $coverage->baseDir . '/' . $coverage->xmlFile);

        $this->config  = $config;
        $this->branch  = $branch;
        $this->phpFile = $phpFile;
        $this->xmlFile = $xmlFile;
    }

    public function run()
    {
        $changes = [];
        $stashed = false;
        chdir($this->config->git->root);
        exec($this->config->git->cmd . ' status --porcelain', $changes);
        if (!empty($changes)) {
            exec($this->config->git->cmd . ' stash');
            $stashed = true;
        }
        $this->runCoverage();
        if (true === $stashed) {
            exec($this->config->git->cmd . ' stash pop');
        }
        $this->updateProcessedRevision();
        $this->writeConfig();
    }

    private function runCoverage()
    {
        chdir($this->config->git->root);
        if (file_exists($this->phpFile)) {
            $this->branchCoverage = $this->readCoveragePhp($this->phpFile);
            $this->diffToTests();
            $this->runChangedTests();
            $this->mergeBranchCoverage();
            $this->writeBranchCoverage();
        } else {
            $this->runAllTests();
        }
    }

    /**
     * bla
     */
    private function diffToTests()
    {
        $cmd         = [
            $this->config->git->cmd,
            'diff',
            '-U0',
            $this->getProcessedRevision(),
            $this->getActualRevision(),
            '|',
            'grep',
            '-E',
            escapeshellarg('^\+\+\+|^@@'),
        ];
        $coverage    = $this->branchCoverage->getData(true);
        $testClasses = [];
        $testMethods = [];
        $diff        = [];
        $file        = '';

        exec(join(' ', $cmd), $diff);

        foreach ($diff as $change) {
            switch (substr($change, 0, 2)) {
                case '++':
                    $file = preg_replace('/\+\+\+ .(.*?\.php)/', $this->config->git->root . '$1', $change);
                    if (0 === strpos($file, '+++')) {
                        continue;
                    }
                    if (false !== strpos($file, $this->config->git->tests)) {
                        $testClasses[] = $this->testFileToFilterClass($file);
                        continue;
                    }
                    $this->deletions[$file]  = [];
                    $this->insertions[$file] = [];
                    break;
                case '@@':
                    if (!isset($this->deletions[$file])) {
                        continue;
                    }
                    $details = [];
                    preg_match('/@@ -(\d+),?(\d+)? \+(\d+),?(\d+)?/', $change, $details);
                    $line  = $details[1];
                    $count = $details[2];
                    if ('' === $count) {
                        $count = 1;
                    }
                    $this->deletions[$file][$line] = $count;

                    $max = $line + ($count ?: 1);
                    while ($line < $max) {
                        if (isset($coverage[$file][$line]) && !empty($coverage[$file][$line])) {
                            $testMethods = array_unique(
                                array_merge($testMethods, $coverage[$file][$line])
                            );
                        }
                        $line++;
                    }
                    if (!array_key_exists(4, $details)) {
                        $details[4] = 1;
                    }
                    if (!empty($details[4])) {
                        $this->insertions[$file][$details[3]] = $details[4];
                    }
                    break;
            }
        }

        $notCoveredByClasses = function ($method) use ($testClasses) {
            foreach ($testClasses as $testClass) {
                if (false !== strpos($method, $testClass)) {
                    return false;
                }
            }

            return true;
        };

        $testsToRun = array_merge(
            $testClasses,
            array_filter($testMethods, $notCoveredByClasses)
        );

        foreach ($testsToRun as &$test) {
            $test = $this->coverageMethodToFilterMethod($test);
        }

        $this->testsToRun = array_unique($testsToRun);
    }

    private function getProcessedRevision()
    {
        if (isset($this->config->git->branches->{$this->branch})) {
            return $this->config->git->branches->{$this->branch};
        }

        return '';
    }

    private function runAllTests()
    {
        $cmd = [
            $this->config->phpunit->phar,
            '--configuration',
            $this->config->phpunit->configuration,
            '--coverage-php',
            $this->phpFile,
            '--coverage-clover',
            $this->xmlFile,
        ];
        passthru(join(' ', $cmd));
    }

    private function updateProcessedRevision()
    {
        $this->config->git->branches->{$this->branch} = $this->getActualRevision();
    }

    private function getActualRevision()
    {
        return trim(shell_exec($this->config->git->cmd . ' rev-parse HEAD'));
    }

    private function testFileToFilterClass($filename)
    {
        return str_replace(
            [$this->config->git->root . '/', $this->config->git->tests . '/', '/', '.php'],
            ['', '', '\\', ''],
            $filename
        );
    }

    private function coverageMethodToFilterMethod($method)
    {
        return substr($method, 0, strpos($method, ' ') ? : 9999);
    }

    private function runChangedTests()
    {
        if (!empty($this->testsToRun)) {
            echo PHP_EOL . 'Running tests with filter for:' . PHP_EOL;
            echo join(PHP_EOL, $this->testsToRun) . PHP_EOL . PHP_EOL;
            $tmp    = tempnam('/tmp', 'coverage-php');
            $cmd    = [
                $this->config->phpunit->phar,
                '--configuration',
                $this->config->phpunit->configuration,
                '--coverage-php',
                $tmp,
            ];
            $filter = escapeshellarg(str_replace('\\', '\\\\', join('|', $this->testsToRun)));
            passthru(join(' ', $cmd) . ' --filter ' . $filter);
            $this->changedCoverage = $this->readCoveragePhp($tmp);
            unlink($tmp);
        }
    }

    private function mergeBranchCoverage()
    {
        if ($this->changedCoverage) {
            $cleanup = new Cleanup_CodeCoverage();
            $cleanup->setFromCoverage($this->branchCoverage);
            $cleanup->removeTests($this->changedCoverage->getTests());
            $cleanup->applyDeletions($this->deletions);
            $cleanup->applyInsertions($this->insertions);
            $cleanup->merge($this->changedCoverage);
        }
    }

    private function readCoveragePhp($file)
    {
        if (version_compare(PHPUnit_Runner_Version::id(), 4, '>=')) {
            $data = require $file;
        } else {
            $data = unserialize(file_get_contents($file));
        }

        return $data;
    }

    private function writeBranchCoverage()
    {
        $php = new PHP_CodeCoverage_Report_PHP();
        $php->process($this->branchCoverage, $this->phpFile);

        $clover = new PHP_CodeCoverage_Report_Clover();
        $clover->process($this->branchCoverage, $this->xmlFile);
    }

    private function writeConfig()
    {
        file_put_contents(
            __DIR__ . '/CoverageAutomation.json',
            json_encode($this->config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}

class Cleanup_CodeCoverage extends PHP_CodeCoverage
{
    /** @var array */
    protected $_data;

    /** @var array */
    protected $_tests;

    /** @var PHP_CodeCoverage */
    protected $coverage;

    /**
     * @param PHP_CodeCoverage $coverage
     */
    public function setFromCoverage(PHP_CodeCoverage $coverage)
    {
        $this->coverage = $coverage;
        if (version_compare(PHPUnit_Runner_Version::id(), 4, '>=')) {
            $this->_data  = $coverage->getData(true);
            $this->_tests = $coverage->getTests();
        } else {
            $this->_data  =& $coverage->data;
            $this->_tests =& $coverage->tests;
        }
    }

    /**
     * @param array $tests
     */
    public function removeTests($tests)
    {
        $diffTests = array_keys($tests);
        foreach ($this->_data as $file => $lines) {
            if (!empty($lines)) {
                foreach ($lines as $line => $coveringTests) {
                    if (!empty($coveringTests)) {
                        $this->_data[$file][$line] = array_diff($coveringTests, $diffTests);
                    }
                }
            }
        }
        $this->_tests = array_diff_key($this->_tests, $tests);
    }

    public function applyDeletions($changes)
    {
        foreach ($changes as $file => $lines) {
            $lines = array_reverse($lines, true);
            reset($lines);
            $this->fillIndexes($file, key($lines));
            foreach ($lines as $line => $count) {
                array_splice($this->_data[$file], $line, $count);
            }
        }
    }

    public function applyInsertions($changes)
    {
        foreach ($changes as $file => $lines) {
            end($lines);
            $this->fillIndexes($file, key($lines));
            foreach ($lines as $line => $count) {
                array_splice($this->_data[$file], $line, 0, array_fill(0, $count, null));
            }
        }
    }

    public function merge(PHP_CodeCoverage $coverage)
    {
        $this->clearFirstTimeHandledForMerge($coverage);
        if (version_compare(PHPUnit_Runner_Version::id(), 4, '>=')) {
            $this->coverage->setData($this->_data);
            $this->coverage->setTests($this->_tests);
        }
        $this->coverage->merge($coverage);
    }

    private function clearFirstTimeHandledForMerge(PHP_CodeCoverage $coverage)
    {
        $data  = $coverage->getData();
        $files = array_keys($data);

        foreach ($files as $file) {
            if (isset($this->_data[$file])) {
                $lines = $this->_data[$file];
                if (!$this->isFirstTimeHandled($data[$file]) && $this->isFirstTimeHandled($lines)) {
                    $this->_data[$file] = [];
                }
            }
        }
    }

    private function isFirstTimeHandled($lines)
    {
        $uncovered = true;
        if (is_array($lines)) {
            end($lines);
            if (key($lines) === count($lines)) {
                $line = reset($lines);
                while (false !== $line) {
                    if ($line !== []) {
                        $uncovered = false;
                        break;
                    }
                    $line = next($lines);
                }
            } else {
                $uncovered = false;
            }
        }
        return $uncovered;
    }

    private function fillIndexes($file, $max)
    {
        if (!isset($this->_data[$file])) {
            $this->_data[$file] = array_fill(0, $max, null);
        } else {
            $lines =& $this->_data[$file];
            ksort($lines);
            end($lines);
            $max = max($max, key($lines));
            for ($i = 0; $i <= $max; $i++) {
                if (!isset($lines[$i])) {
                    $lines[$i] = null;
                }
            }
            ksort($lines);
        }
    }
}

$ca = new CoverageAutomation($config);
$ca->run();
