<?php

$config     = json_decode(file_get_contents(__DIR__ . '/CoverageAutomation.json'));
$scriptName = $GLOBALS['_SERVER']['SCRIPT_NAME'];

$GLOBALS['_SERVER']['SCRIPT_NAME'] = '-';
include "phar://{$config->phpunit->phar}";

$GLOBALS['_SERVER']['SCRIPT_NAME'] = $scriptName;

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
        $this->runCoverage();
    }

    private function runCoverage()
    {
        chdir($this->config->git->root);
        if (file_exists($this->phpFile)) {
            $this->branchCoverage = unserialize(file_get_contents($this->phpFile));
            $this->diffToTests();
            $this->runChangedTests();
            $this->mergeBranchCoverage();
            $this->writeBranchCoverage();
        } else {
            $this->runAllTests();
        }
        $this->updateProcessedRevision();
        $this->writeConfig();
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
        $coverage    = $this->branchCoverage->getData();
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
            $this->changedCoverage = unserialize(file_get_contents($tmp));
            unlink($tmp);
        }
    }

    private function mergeBranchCoverage()
    {
        if ($this->changedCoverage) {
            $this->branchCoverage->setAddUncoveredFilesFromWhitelist(true);

            $cleanup = new Cleanup_CodeCoverage();
            $cleanup->setFromCoverage($this->branchCoverage);
            $cleanup->removeTests($this->changedCoverage->getTests());
            $cleanup->applyDeletions($this->deletions);
            $cleanup->applyInsertions($this->insertions);

//            $this->changedCoverage->setAddUncoveredFilesFromWhitelist(false);
            $this->branchCoverage->merge($this->changedCoverage);
        }
    }

    private function writeBranchCoverage()
    {
        file_put_contents($this->phpFile, serialize($this->branchCoverage));
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
    protected $data;

    /** @var array */
    protected $tests;

    /**
     * @param PHP_CodeCoverage $coverage
     */
    public function setFromCoverage(PHP_CodeCoverage $coverage)
    {
        $this->data  =& $coverage->data;
        $this->tests =& $coverage->tests;
    }

    /**
     * @param array $tests
     */
    public function removeTests($tests)
    {
        $diffTests = array_keys($tests);
        foreach ($this->data as $file => $lines) {
            if (!empty($lines)) {
                foreach ($lines as $line => $coveringTests) {
                    if (!empty($coveringTests)) {
                        $this->data[$file][$line] = array_diff($coveringTests, $diffTests);
                    }
                }
            }
        }
        $this->tests = array_diff_key($this->tests, $tests);
    }

    public function applyDeletions($changes)
    {
        foreach ($changes as $file => $lines) {
            $lines = array_reverse($lines, true);
            reset($lines);
            $this->fillIndexes($file, key($lines));
            foreach ($lines as $line => $count) {
                array_splice($this->data[$file], $line, $count);
            }
        }
    }

    public function applyInsertions($changes)
    {
        foreach ($changes as $file => $lines) {
            end($lines);
            $this->fillIndexes($file, key($lines));
            foreach ($lines as $line => $count) {
                array_splice($this->data[$file], $line, 0, array_fill(0, $count, []));
            }
        }
    }

    private function fillIndexes($file, $max)
    {
        if (!isset($this->data[$file])) {
            $this->data[$file] = array_fill(0, $max, []);
        } else {
            $lines =& $this->data[$file];
            ksort($lines);
            end($lines);
            $max = max($max, key($lines));
            for ($i = 0; $i <= $max; $i++) {
                if (!isset($lines[$i])) {
                    $lines[$i] = [];
                }
            }
            ksort($lines);
        }
    }
}

$ca = new CoverageAutomation($config);
$ca->run();
