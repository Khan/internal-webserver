<?php

/**
 * NOTE: This is very new and unstable.
 */
final class PhutilSpriteSheet {

  const MANIFEST_VERSION = 1;

  private $sprites = array();
  private $sources = array();
  private $hashes  = array();
  private $cssHeader;
  private $generated;
  private $scales = array(1);

  private $css;
  private $images;

  public function addSprite(PhutilSprite $sprite) {
    $this->generated = false;
    $this->sprites[] = $sprite;
    return $this;
  }

  public function setCSSHeader($header) {
    $this->generated = false;
    $this->cssHeader = $header;
    return $this;
  }

  public function setScales(array $scales) {
    $this->scales = array_values($scales);
    return $this;
  }

  private function generate() {
    if ($this->generated) {
      return;
    }

    $css = array();
    if ($this->cssHeader) {
      $css[] = $this->cssHeader;
    }

    $margin_w = 1;
    $margin_h = 1;

    $out_w = 0;
    $out_h = 0;

    // Lay out the sprite sheet. We attempt to build a roughly square sheet
    // so it's easier to manage, since 2000x20 is more cumbersome for humans
    // to deal with than 200x200.
    //
    // To do this, we use a simple greedy algorithm, adding sprites one at a
    // time. For each sprite, if the sheet is at least as wide as it is tall
    // we create a new row. Otherwise, we try to add it to an existing row.
    //
    // This isn't optimal, but does a reasonable job in most cases and isn't
    // too messy.

    // Group the sprites by their sizes. We lay them out in the sheet as
    // boxes, but then put them into the boxes in the order they were added
    // so similar sprites end up nearby on the final sheet.
    $boxes = array();
    foreach (array_reverse($this->sprites) as $sprite) {
      $s_w = $sprite->getSourceW() + $margin_w;
      $s_h = $sprite->getSourceH() + $margin_h;
      $boxes[$s_w][$s_h][] = $sprite;
    }

    $rows = array();
    foreach ($this->sprites as $sprite) {
      $s_w = $sprite->getSourceW() + $margin_w;
      $s_h = $sprite->getSourceH() + $margin_h;

      // Choose a row for this sprite.
      $maybe = array();
      foreach ($rows as $key => $row) {
        if ($row['h'] < $s_h) {
          // We can only add it to a row if the row is at least as tall as the
          // sprite.
          continue;
        }
        // We prefer rows which have the same height as the sprite, and then
        // rows which aren't yet very wide.
        $wasted_v = ($row['h'] - $s_h);
        $wasted_h = ($row['w'] / $out_w);
        $maybe[$key] = $wasted_v + $wasted_h;
      }

      $row_key = null;
      if ($maybe) {
        // If there were any candidate rows, pick the best one.
        asort($maybe);
        $row_key = head_key($maybe);
      }

      if ($row_key !== null) {
        // If there's a candidate row, but adding the sprite to it would make
        // the sprite wider than it is tall, create a new row instead. This
        // generally keeps the sprite square-ish.
        if ($rows[$row_key]['w'] + $s_w > $out_h) {
          $row_key = null;
        }
      }

      if ($row_key === null) {
        // Add a new row.
        $rows[] = array(
          'w'       => 0,
          'h'       => $s_h,
          'boxes'   => array(),
        );
        $row_key = last_key($rows);
        $out_h += $s_h;
      }

      // Add the sprite box to the row.
      $row = $rows[$row_key];
      $row['w'] += $s_w;
      $row['boxes'][] = array($s_w, $s_h);
      $rows[$row_key] = $row;

      $out_w = max($row['w'], $out_w);
    }

    $images = array();
    foreach ($this->scales as $scale) {
      $img = imagecreatetruecolor($out_w * $scale, $out_h * $scale);
      imagesavealpha($img, true);
      imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

      $images[$scale] = $img;
    }


    // Put the shorter rows first. At the same height, put the wider rows first.
    // This makes the resulting sheet more human-readable.
    foreach ($rows as $key => $row) {
      $rows[$key]['sort'] = $row['h'] + (1 - ($row['w'] / $out_w));
    }
    $rows = isort($rows, 'sort');

    $pos_x = 0;
    $pos_y = 0;
    foreach ($rows as $row) {
      $max_h = 0;
      foreach ($row['boxes'] as $box) {
        $sprite = array_pop($boxes[$box[0]][$box[1]]);

        foreach ($images as $scale => $img) {
          $src = $this->loadSource($sprite, $scale);
          imagecopy(
            $img,
            $src,
            $scale * $pos_x,                $scale * $pos_y,
            $scale * $sprite->getSourceX(), $scale * $sprite->getSourceY(),
            $scale * $sprite->getSourceW(), $scale * $sprite->getSourceH());
        }

        $rule = $sprite->getTargetCSS();
        $cssx = (-$pos_x).'px';
        $cssy = (-$pos_y).'px';

        $css[] = "{$rule} {\n  background-position: {$cssx} {$cssy};\n}";

        $pos_x += $sprite->getSourceW() + $margin_w;
        $max_h = max($max_h, $sprite->getSourceH());
      }
      $pos_x = 0;
      $pos_y += $max_h + $margin_h;
    }

    $this->images = $images;
    $this->css = implode("\n\n", $css)."\n";
    $this->generated = true;
  }

  public function generateImage($path, $scale = 1) {
    $this->generate();
    $this->log("Writing sprite '{$path}'...");
    imagepng($this->images[$scale], $path);
    return $this;
  }

  public function generateCSS($path) {
    $this->generate();
    $this->log("Writing CSS '{$path}'...");

    $out = $this->css;
    $out = str_replace('{X}', imagesx($this->images[1]), $out);
    $out = str_replace('{Y}', imagesy($this->images[1]), $out);

    Filesystem::writeFile($path, $out);
    return $this;
  }

  public function needsRegeneration(array $manifest) {
    return ($this->buildManifest() !== $manifest);
  }

  private function buildManifest() {
    $output = array();
    foreach ($this->sprites as $sprite) {
      $output[$sprite->getName()] = array(
        'name' => $sprite->getName(),
        'rule' => $sprite->getTargetCSS(),
        'hash' => $this->loadSourceHash($sprite),
      );
    }

    ksort($output);

    $data = array(
      'version' => self::MANIFEST_VERSION,
      'sprites' => $output,
      'scales'  => $this->scales,
      'header'  => $this->cssHeader,
    );

    return $data;
  }

  public function generateManifest($path) {
    $data = $this->buildManifest();

    $json = new PhutilJSON();
    $data = $json->encodeFormatted($data);
    Filesystem::writeFile($path, $data);
    return $this;
  }

  private function log($message) {
    echo $message."\n";
  }

  private function loadSourceHash(PhutilSprite $sprite) {
    $inputs = array();

    foreach ($this->scales as $scale) {
      $file = $sprite->getSourceFile($scale);
      if (empty($this->hashes[$file])) {
        $this->hashes[$file] = md5(Filesystem::readFile($file));
      }
      $inputs[] = $file;
      $inputs[] = $this->hashes[$file];
    }

    $inputs[] = $sprite->getSourceX();
    $inputs[] = $sprite->getSourceY();
    $inputs[] = $sprite->getSourceW();
    $inputs[] = $sprite->getSourceH();

    return md5(implode(':', $inputs));
  }

  private function loadSource(PhutilSprite $sprite, $scale) {
    $file = $sprite->getSourceFile($scale);
    if (empty($this->sources[$file])) {
      $data = Filesystem::readFile($file);
      $image = imagecreatefromstring($data);
      $this->sources[$file] = array(
        'image' => $image,
        'x'     => imagesx($image),
        'y'     => imagesy($image),
      );
    }

    $s_w = $sprite->getSourceW() * $scale;
    $i_w = $this->sources[$file]['x'];
    if ($s_w > $i_w) {
      throw new Exception(
        "Sprite source for '{$file}' is too small (expected width {$s_w}, ".
        "found {$i_w}).");
    }

    $s_h = $sprite->getSourceH() * $scale;
    $i_h = $this->sources[$file]['y'];
    if ($s_h > $i_h) {
      throw new Exception(
        "Sprite source for '{$file}' is too small (expected height {$s_h}, ".
        "found {$i_h}).");
    }

    return $this->sources[$file]['image'];
  }

}
