<?php

final class PhutilPHPObjectProtocolChannelTestCase extends PhutilTestCase {

  public function testPHPObjectChannelBasics() {
    list($x, $y) = PhutilSocketChannel::newChannelPair();
    $xp = new PhutilPHPObjectProtocolChannel($x);
    $yp = new PhutilPHPObjectProtocolChannel($y);

    $object = (object)array(
      'key' => mt_rand(),
    );

    $xp->write($object);
    $xp->flush();
    $result = $yp->waitForMessage();

    $this->assertEqual(
      true,
      (array)$object === (array)$result,
      "Values are identical.");

    $this->assertEqual(
      false,
      $object === $result,
      "Objects are not the same.");
  }

  public function testCloseSocketWriteChannel() {
    list($x, $y) = PhutilSocketChannel::newChannelPair();
    $xp = new PhutilPHPObjectProtocolChannel($x);
    $yp = new PhutilPHPObjectProtocolChannel($y);

    $yp->closeWriteChannel();
    $yp->update();

    // NOTE: This test is more broad than the implementation needs to be. A
    // better test would be to verify that this throws an exception:
    //
    //   $xp->waitForMessage();
    //
    // However, if the test breaks, that method will hang forever instead of
    // returning, which would be hard to diagnose. Since the current
    // implementation shuts down the entire channel, just test for that.

    $this->assertEqual(false, $xp->update(), 'Expected channel to close.');
  }

  public function testCloseExecWriteChannel() {
    $future = new ExecFuture('cat');

    // If this test breaks, we want to explode, not hang forever.
    $future->setTimeout(5);

    $exec_channel = new PhutilExecChannel($future);
    $exec_channel->write("quack");
    $exec_channel->closeWriteChannel();

    // If `closeWriteChannel()` did what it is supposed to, this will just
    // echo "quack" and exit with no error code. If the channel did not close,
    // this will time out after 5 seconds and throw.
    $future->resolvex();
  }


}
