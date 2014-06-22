<?php

class CoverageAutomationTest extends PHPUnit_Framework_TestCase
{
    const START_HASH = 'c5f15aceb8bad4a74a3b33f1ddf13774531ae814';

    public static function setUpBeforeClass()
    {
//        shell_exec(__DIR__ . '/../bin/generate-fixtures.sh');
    }

    /**
     * @param $fromCommit
     * @param $toCommit
     *
     * @dataProvider provideTestCoverageHashes
     */
    public function testCoverage($fromCommit, $toCommit)
    {
        $previousSerialized = __DIR__ . '/fixtures/' . $fromCommit . '.serialized';
        $coverageSerialized = __DIR__ . '/../project/app/coverage-HEAD.serialized';

        $expectedXml = __DIR__ . '/fixtures/' . $toCommit . '.xml';
        $coverageXml = __DIR__ . '/../project/app/coverage-HEAD.xml';

        $this->writeCoverageConfig($fromCommit);

        chdir(__DIR__ . '/../project');
        exec('git checkout --detach -q ' . $toCommit);

        if (file_exists($coverageSerialized)) {
            unlink($coverageSerialized);
        }
        if (file_exists($previousSerialized)) {
            copy($previousSerialized, $coverageSerialized);
        }

        if (isset($_ENV['XDEBUG_CONFIG'])) {
            $cmd = [
                'php',
                '-dxdebug.remote_enable=1',
                '-dxdebug.remote_mode=req',
                '-dxdebug.remote_port=9000',
                '-dxdebug.remote_host=127.0.0.1',
                __DIR__ . '/../bin/CoverageAutomation.php',
            ];
        } else {
            $cmd = [
                'php',
                __DIR__ . '/../bin/CoverageAutomation.php'
            ];
        }

        exec(join(' ', $cmd));

        file_put_contents(
            $coverageXml,
            preg_replace('/(generated|timestamp)="\d+"/', '$1="1"', file_get_contents($coverageXml))
        );

        $this->assertXmlFileEqualsXmlFile(
            $expectedXml,
            $coverageXml,
            "coverage between $fromCommit and $toCommit"
        );
    }

    public function provideTestCoverageHashes()
    {
        $hashes = [];
        $data   = [];
        chdir(__DIR__ . '/../project');
        exec('git checkout -q master');
        exec('git rev-list --reverse HEAD ^' . self::START_HASH, $hashes);

        array_unshift($hashes, self::START_HASH);
        reset($hashes);

        while (false !== next($hashes)) {
            $data[] = [
                prev($hashes),
                next($hashes),
            ];
        }

        return $data;
    }

    protected function writeCoverageConfig($commit)
    {
        $config = json_decode(file_get_contents(__DIR__ . '/../bin/CoverageAutomation.json.dist'));

        $config->git->branches->HEAD = $commit;

        file_put_contents(
            __DIR__ . '/../bin/CoverageAutomation.json',
            json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
 