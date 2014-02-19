<?php

final class PhabricatorNotificationTestController
  extends PhabricatorNotificationController {

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $story_type = 'PhabricatorNotificationAdHocFeedStory';
    $story_data = array(
      'title' => pht(
        'This is a test notification, sent at %s.',
        phabricator_datetime(time(), $viewer)),
    );

    $viewer_phid = $viewer->getPHID();

    // TODO: When it's easier to get these buttons to render as forms, this
    // would be slightly nicer as a more standard isFormPost() check.

    if ($request->validateCSRF()) {
      id(new PhabricatorFeedStoryPublisher())
        ->setStoryType($story_type)
        ->setStoryData($story_data)
        ->setStoryTime(time())
        ->setStoryAuthorPHID($viewer_phid)
        ->setRelatedPHIDs(array($viewer_phid))
        ->setPrimaryObjectPHID($viewer_phid)
        ->setSubscribedPHIDs(array($viewer_phid))
        ->setNotifyAuthor(true)
        ->publish();
    }

    return id(new AphrontAjaxResponse());
  }

}
