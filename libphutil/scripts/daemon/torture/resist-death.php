#!/usr/bin/env php
<?php

// This script just creates a process which is difficult to terminate. It is
// used for daemon resiliance tests.

declare(ticks = 1);
pcntl_signal(SIGTERM, 'ignore');
pcntl_signal(SIGINT,  'ignore');

function ignore($signo) {
  return;
}

echo "Resisting death; sleeping forever...\n";

while (true) {
  sleep(60);
}
