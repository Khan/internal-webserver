<?php

final class FeedPublisherHTTPWorker extends FeedPushWorker {

  protected function doWork() {
    $story = $this->loadFeedStory();
    $data = $story->getStoryData();

    $uri = idx($this->getTaskData(), 'uri');

    $post_data = array(
      'storyID'         => $data->getID(),
      'storyType'       => $data->getStoryType(),
      'storyData'       => $data->getStoryData(),
      'storyAuthorPHID' => $data->getAuthorPHID(),
      'epoch'           => $data->getEpoch(),
    );

    id(new HTTPFuture($uri, $post_data))
      ->setMethod('POST')
      ->setTimeout(30)
      ->resolvex();
  }

  public function getWaitBeforeRetry(PhabricatorWorkerTask $task) {
    return max($task->getFailureCount(), 1) * 60;
  }

}
