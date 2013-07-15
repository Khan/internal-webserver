<?php

/**
 * @group markup
 * @concrete-extensible (TODO only needed by Facebook at the moment)
 */
class PhutilRemarkupRuleHyperlink
  extends PhutilRemarkupRule {

  public function getPriority() {
    return 400.0;
  }

  public function apply($text) {

    // Hyperlinks with explicit "<>" around them get linked exactly, without
    // the "<>". Angle brackets are basically special and mean "this is a URL
    // with weird characters". This is assumed to be reasonable because they
    // don't appear in normal text or normal URLs.
    $text = preg_replace_callback(
      '@<(\w{3,}://.+?)>@',
      array($this, 'markupHyperlink'),
      $text);

    // Anything else we match "ungreedily", which means we'll look for
    // stuff that's probably puncutation or otherwise not part of the URL and
    // not link it. This lets someone write "QuicK! Go to
    // http://www.example.com/!". We also apply some paren balancing rules.
    $text = preg_replace_callback(
      '@(\w{3,}://\S+)@',
      array($this, 'markupHyperlinkUngreedy'),
      $text);

    return $text;
  }

  protected function markupHyperlink($matches) {

    $protocols = $this->getEngine()->getConfig(
      'uri.allowed-protocols',
      array());

    $protocol = id(new PhutilURI($matches[1]))->getProtocol();
    if (!idx($protocols, $protocol)) {
      // If this URI doesn't use a whitelisted protocol, don't link it. This
      // is primarily intended to prevent javascript:// silliness.
      return $this->getEngine()->storeText($matches[1]);
    }

    return $this->storeRenderedHyperlink($matches[1]);
  }

  protected function storeRenderedHyperlink($link) {
    return $this->getEngine()->storeText($this->renderHyperlink($link));
  }

  protected function renderHyperlink($link) {
    if ($this->getEngine()->isTextMode()) {
      return $link;
    }

    if ($this->getEngine()->getState('toc')) {
      return $link;
    } else {
      return phutil_tag(
        'a',
        array(
          'href'    => $link,
          'target'  => '_blank',
        ),
        $link);
    }
  }

  protected function markupHyperlinkUngreedy($matches) {
    $match = $matches[1];
    $tail = null;
    $trailing = null;
    if (preg_match('/[;,.:!?]+$/', $match, $trailing)) {
      $tail = $trailing[0];
      $match = substr($match, 0, -strlen($tail));
    }

    // If there's a closing paren at the end but no balancing open paren in
    // the URL, don't link the close paren. This is an attempt to gracefully
    // handle the two common paren cases, Wikipedia links and English language
    // parentheticals, e.g.:
    //
    //  http://en.wikipedia.org/wiki/Noun_(disambiguation)
    //  (see also http://www.example.com)
    //
    // We could apply a craftier heuristic here which tries to actually balance
    // the parens, but this is probably sufficient.
    if (preg_match('/\\)$/', $match) && !preg_match('/\\(/', $match)) {
      $tail = ')'.$tail;
      $match = substr($match, 0, -1);
    }

    return hsprintf('%s%s', $this->markupHyperlink(array(null, $match)), $tail);
  }

}
