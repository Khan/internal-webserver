<?php

/**
 * @group irc
 */
final class PhabricatorBotMacroHandler extends PhabricatorBotHandler {

  private $macros;
  private $regexp;

  private $buffer = array();
  private $next   = 0;

  private function init() {
    if ($this->macros === false) {
      return false;
    }

    if ($this->macros !== null) {
      return true;
    }

    $macros = $this->getConduit()->callMethodSynchronous(
      'macro.query',
      array());
    // bail if we have no macros
    if (empty($macros)) {
      return false;
    }
    $this->macros = $macros;

    $regexp = array();
    foreach ($this->macros as $macro_name => $macro) {
      $regexp[] = preg_quote($macro_name, '/');
    }
    $regexp = '/('.implode('|', $regexp).')/';

    $this->regexp = $regexp;

    return true;
  }

  public function receiveMessage(PhabricatorBotMessage $message) {
    if (!$this->init()) {
      return;
    }

    switch ($message->getCommand()) {
      case 'PRIVMSG':
        $reply_to = $message->getReplyTo();
        if (!$reply_to) {
          break;
        }

        $message = $message->getMessageText();

        $matches = null;
        if (!preg_match($this->regexp, $message, $matches)) {
          return;
        }

        $macro = $matches[1];

        $ascii = idx($this->macros[$macro], 'ascii');
        if ($ascii === false) {
          return;
        }

        if (!$ascii) {
          $this->macros[$macro]['ascii'] = $this->rasterize(
            $this->macros[$macro],
            $this->getConfig('macro.size', 48),
            $this->getConfig('macro.aspect', 0.66));
          $ascii = $this->macros[$macro]['ascii'];
        }

        foreach ($ascii as $line) {
          $this->buffer[$reply_to][] = $line;
        }
        break;
    }
  }

  public function runBackgroundTasks() {
    if (microtime(true) < $this->next) {
      return;
    }

    foreach ($this->buffer as $channel => $lines) {
      if (empty($lines)) {
        unset($this->buffer[$channel]);
        continue;
      }
      foreach ($lines as $key => $line) {
        $this->write('PRIVMSG', "{$channel} :{$line}");
        unset($this->buffer[$channel][$key]);
        break 2;
      }
    }

    $sleep = $this->getConfig('macro.sleep', 0.25);
    $this->next = microtime(true) + ((mt_rand(75, 150) / 100) * $sleep);
  }

  public function rasterize($macro, $size, $aspect) {
    $image = HTTPSFuture::loadContent($macro['uri']);
    if (!$image) {
      return false;
    }

    $img = @imagecreatefromstring($image);
    if (!$img) {
      return false;
    }

    $sx = imagesx($img);
    $sy = imagesy($img);

    if ($sx > $size || $sy > $size) {
      $scale = max($sx, $sy) / $size;
      $dx = floor($sx / $scale);
      $dy = floor($sy / $scale);
    } else {
      $dx = $sx;
      $dy = $sy;
    }

    $dy = floor($dy * $aspect);

    $dst = imagecreatetruecolor($dx, $dy);
    if (!$dst) {
      return false;
    }
    imagealphablending($dst, false);

    $ok = imagecopyresampled(
      $dst, $img,
      0, 0,
      0, 0,
      $dx, $dy,
      $sx, $sy);

    if (!$ok) {
      return false;
    }

    $map = array(
      ' ',
      '.',
      ',',
      ':',
      ';',
      '!',
      '|',
      '*',
      '=',
      '@',
      '$',
      '#',
    );

    $lines = array();

    for ($ii = 0; $ii < $dy; $ii++) {
      $buf = '';
      for ($jj = 0; $jj < $dx; $jj++) {
        $c = imagecolorat($dst, $jj, $ii);

        $a = ($c >> 24) & 0xFF;
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = ($c) & 0xFF;

        $luma = (255 - ((0.30 * $r) + (0.59 * $g) + (0.11 * $b))) / 256;
        $luma *= ((127 - $a) / 127);

        $char = $map[max(0, floor($luma * count($map)))];
        $buf .= $char;
      }

      $lines[] = $buf;
    }

    return $lines;
  }

}
