<?php

final class DoorkeeperTagsController extends PhabricatorController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $tags = $request->getStr('tags');
    $tags = json_decode($tags, true);
    if (!is_array($tags)) {
      $tags = array();
    }

    $refs = array();
    $id_map = array();
    foreach ($tags as $tag_spec) {
      $tag = $tag_spec['ref'];
      $ref = id(new DoorkeeperObjectRef())
        ->setApplicationType($tag[0])
        ->setApplicationDomain($tag[1])
        ->setObjectType($tag[2])
        ->setObjectID($tag[3]);

      $key = $ref->getObjectKey();
      $id_map[$key] = $tag_spec['id'];
      $refs[$key] = $ref;
    }

    $refs = id(new DoorkeeperImportEngine())
      ->setViewer($viewer)
      ->setRefs($refs)
      ->execute();

    $results = array();
    foreach ($refs as $key => $ref) {
      if (!$ref->getIsVisible()) {
        continue;
      }

      $uri = $ref->getExternalObject()->getObjectURI();
      if (!$uri) {
        continue;
      }

      $id = $id_map[$key];

      $tag = id(new PhabricatorTagView())
        ->setID($id)
        ->setName($ref->getFullName())
        ->setHref($uri)
        ->setType(PhabricatorTagView::TYPE_OBJECT)
        ->setExternal(true)
        ->render();

      $results[] = array(
        'id'      => $id,
        'markup'  => $tag,
      );
    }

    return id(new AphrontAjaxResponse())->setContent(
      array(
        'tags' => $results,
      ));
  }


}
