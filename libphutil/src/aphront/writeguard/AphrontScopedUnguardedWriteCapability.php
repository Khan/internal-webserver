<?php

/**
 * @group aphront
 */
final class AphrontScopedUnguardedWriteCapability {

  final public function __destruct() {
    AphrontWriteGuard::endUnguardedWrites();
  }

}
