<?php

/**
 * @group conduit
 */
final class ConduitAPI_nuance_createitem_Method
  extends ConduitAPI_nuance_Method {

  public function getMethodDescription() {
    return pht('Create a new item.');
  }

  public function defineParamTypes() {
    return array(
      'requestorPHID' => 'required string',
      'sourcePHID'    => 'required string',
      'ownerPHID'     => 'optional string',
    );
  }

  public function defineReturnType() {
    return 'nonempty dict';
  }

  public function defineErrorTypes() {
    return array(
      'ERR-NO-REQUESTOR-PHID' => pht('Items must have a requestor.'),
      'ERR-NO-SOURCE-PHID' => pht('Items must have a source.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $source_phid = $request->getValue('sourcePHID');
    $owner_phid = $request->getValue('ownerPHID');
    $requestor_phid = $request->getValue('requestorPHID');

    $user = $request->getUser();

    $item = NuanceItem::initializeNewItem($user);
    $xactions = array();

    if ($source_phid) {
      $xactions[] = id(new NuanceItemTransaction())
        ->setTransactionType(NuanceItemTransaction::TYPE_SOURCE)
        ->setNewValue($source_phid);
    } else {
      throw new ConduitException('ERR-NO-SOURCE-PHID');
    }

    if ($owner_phid) {
      $xactions[] = id(new NuanceItemTransaction())
        ->setTransactionType(NuanceItemTransaction::TYPE_OWNER)
        ->setNewValue($owner_phid);
    }

    if ($requestor_phid) {
      $xactions[] = id(new NuanceItemTransaction())
        ->setTransactionType(NuanceItemTransaction::TYPE_REQUESTOR)
        ->setNewValue($requestor_phid);
    } else {
      throw new ConduitException('ERR-NO-REQUESTOR-PHID');
    }

    $source = PhabricatorContentSource::newFromConduitRequest($request);
    $editor = id(new NuanceItemEditor())
      ->setActor($user)
      ->setContentSource($source)
      ->applyTransactions($item, $xactions);

    return $item->toDictionary();
  }

}
