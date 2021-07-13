<?php

namespace HM\Cavalcade\Runner\Tests;

class Test_Singleton extends CavalcadeRunner_TestCase
{
    protected function start_runner()
    {
        $this->start_runner_process();
        # Never open gate at this time.
    }

    function test_normal()
    {
        file_put_contents(PUBLIC_IP, EIP);
        flock($this->lockfile, LOCK_UN);

        self::wait_runner_blocking();
        $this->assertFileExists(RUNNER_STARTED);
    }

    function test_wait_for_eip()
    {
        flock($this->lockfile, LOCK_UN);
        sleep(15);
        file_put_contents(PUBLIC_IP, EIP);

        self::wait_runner_blocking();
        $this->assertFileExists(RUNNER_STARTED);
    }

    function test_wait_for_lock()
    {
        file_put_contents(PUBLIC_IP, EIP);
        sleep(15);
        flock($this->lockfile, LOCK_UN);

        self::wait_runner_blocking();
        $this->assertFileExists(RUNNER_STARTED);
    }

    function test_no_eip()
    {
        flock($this->lockfile, LOCK_UN);
        sleep(11);

        $this->assertFileNotExists(RUNNER_STARTED);
    }

    function test_no_lock()
    {
        file_put_contents(PUBLIC_IP, EIP);
        sleep(11);

        $this->assertFileNotExists(RUNNER_STARTED);
    }

    function test_eip_stolen()
    {
        file_put_contents(PUBLIC_IP, EIP);
        flock($this->lockfile, LOCK_UN);
        self::wait_runner_blocking();
        sleep(20);
        file_put_contents(PUBLIC_IP, EPHEMERAL_IP);
        sleep(10);

        // Lock released because the program exited.
        $this->assertTrue(flock($this->lockfile, LOCK_EX | LOCK_NB));
    }

    function test_locked_while_running()
    {
        file_put_contents(PUBLIC_IP, EIP);
        flock($this->lockfile, LOCK_UN);
        self::wait_runner_blocking();
        sleep(20);
        $this->assertFalse(flock($this->lockfile, LOCK_EX | LOCK_NB));
    }

    function test_maintenance_mode()
    {
        file_put_contents(DOT_MAINTENANCE, '<?php ');

        file_put_contents(PUBLIC_IP, EIP);
        flock($this->lockfile, LOCK_UN);

        sleep(20);

        $this->assertFileNotExists(RUNNER_STARTED);
    }
}
