<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group pholio
 */
final class PholioMockViewController extends PholioController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $mock = id(new PholioMockQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();

    if (!$mock) {
      return new Aphront404Response();
    }

    $xactions = id(new PholioTransactionQuery())
      ->withMockIDs(array($mock->getID()))
      ->execute();

    $subscribers = PhabricatorSubscribersQuery::loadSubscribersForPHID(
      $mock->getPHID());

    $phids = array();
    $phids[] = $mock->getAuthorPHID();
    foreach ($xactions as $xaction) {
      $phids[] = $xaction->getAuthorPHID();
      foreach ($xaction->getRequiredHandlePHIDs() as $hphid) {
        $phids[] = $hphid;
      }
    }
    foreach ($subscribers as $subscriber) {
      $phids[] = $subscriber;
    }
    $this->loadHandles($phids);


    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($user);
    $engine->addObject($mock, PholioMock::MARKUP_FIELD_DESCRIPTION);
    foreach ($xactions as $xaction) {
      $engine->addObject($xaction, PholioTransaction::MARKUP_FIELD_COMMENT);
    }
    $engine->process();

    $title = 'M'.$mock->getID().' '.$mock->getName();

    $header = id(new PhabricatorHeaderView())
      ->setHeader($title);

    $actions = $this->buildActionView($mock);
    $properties = $this->buildPropertyView($mock, $engine, $subscribers);

    $carousel =
      '<h1 style="margin: 2em; padding: 1em; border: 1px dashed grey;">'.
        'Carousel Goes Here</h1>';

    $xaction_view = $this->buildTransactionView($xactions, $engine);

    $add_comment = $this->buildAddCommentView($mock);

    $content = array(
      $header,
      $actions,
      $properties,
      $carousel,
      $xaction_view,
      $add_comment,
    );

    return $this->buildApplicationPage(
      $content,
      array(
        'title' => $title,
        'device' => true,
      ));
  }

  private function buildActionView(PholioMock $mock) {
    $user = $this->getRequest()->getUser();

    $actions = id(new PhabricatorActionListView())
      ->setUser($user)
      ->setObject($mock);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $mock,
      PhabricatorPolicyCapability::CAN_EDIT);

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('edit')
        ->setName(pht('Edit Mock'))
        ->setHref($this->getApplicationURI('/edit/'.$mock->getID()))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    return $actions;
  }

  private function buildPropertyView(
    PholioMock $mock,
    PhabricatorMarkupEngine $engine,
    array $subscribers) {

    $user = $this->getRequest()->getUser();

    $properties = new PhabricatorPropertyListView();

    $properties->addProperty(
      pht('Author'),
      $this->getHandle($mock->getAuthorPHID())->renderLink());

    $properties->addProperty(
      pht('Created'),
      phabricator_datetime($mock->getDateCreated(), $user));

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $user,
      $mock);

    $properties->addProperty(
      pht('Visible To'),
      $descriptions[PhabricatorPolicyCapability::CAN_VIEW]);

    if ($subscribers) {
      $sub_view = array();
      foreach ($subscribers as $subscriber) {
        $sub_view[] = $this->getHandle($subscriber)->renderLink();
      }
      $sub_view = implode(', ', $sub_view);
    } else {
      $sub_view = '<em>'.pht('None').'</em>';
    }

    $properties->addProperty(
      pht('Subscribers'),
      $sub_view);

    $properties->addTextContent(
      $engine->getOutput($mock, PholioMock::MARKUP_FIELD_DESCRIPTION));

    return $properties;
  }

  private function buildAddCommentView(PholioMock $mock) {
    $user = $this->getRequest()->getUser();

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $title = $is_serious
      ? pht('Add Comment')
      : pht('History Beckons');

    $header = id(new PhabricatorHeaderView())
      ->setHeader($title);

    $action = $is_serious
      ? pht('Add Comment')
      : pht('Answer The Call');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setAction($this->getApplicationURI('/comment/'.$mock->getID().'/'))
      ->setWorkflow(true)
      ->setFlexible(true)
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setName('comment')
          ->setLabel(pht('Comment'))
          ->setUser($user))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($action));

    return array(
      $header,
      $form,
    );
  }

  private function buildTransactionView(
    array $xactions,
    PhabricatorMarkupEngine $engine) {
    assert_instances_of($xactions, 'PholioTransaction');

    $view = new PhabricatorTimelineView();

    foreach ($xactions as $xaction) {
      $author = $this->getHandle($xaction->getAuthorPHID());

      $old = $xaction->getOldValue();
      $new = $xaction->getNewValue();

      $xaction_visible = true;
      $title = null;
      $type = $xaction->getTransactionType();

      switch ($type) {
        case PholioTransactionType::TYPE_NONE:
          $title = pht(
            '%s added a comment.',
            $author->renderLink());
          break;
        case PholioTransactionType::TYPE_NAME:
          if ($old === null) {
            $xaction_visible = false;
            break;
          }
          $title = pht(
            '%s renamed this mock from "%s" to "%s".',
            $author->renderLink(),
            phutil_escape_html($old),
            phutil_escape_html($new));
          break;
        case PholioTransactionType::TYPE_DESCRIPTION:
          if ($old === null) {
            $xaction_visible = false;
            break;
          }
          // TODO: Show diff, like Maniphest.
          $title = pht(
            '%s updated the description of this mock. '.
            'The old description was: %s',
            $author->renderLink(),
            phutil_escape_html($old));
          break;
        case PholioTransactionType::TYPE_VIEW_POLICY:
          if ($old === null) {
            $xaction_visible = false;
            break;
          }
          // TODO: Render human-readable.
          $title = pht(
            '%s changed the visibility of this mock from "%s" to "%s".',
            $author->renderLink(),
            phutil_escape_html($old),
            phutil_escape_html($new));
          break;
        case PholioTransactionType::TYPE_SUBSCRIBERS:
          $rem = array_diff($old, $new);
          $add = array_diff($new, $old);

          $add_l = array();
          foreach ($add as $phid) {
            $add_l[] = $this->getHandle($phid)->renderLink();
          }
          $add_l = implode(', ', $add_l);

          $rem_l = array();
          foreach ($rem as $phid) {
            $rem_l[] = $this->getHandle($phid)->renderLink();
          }
          $rem_l = implode(', ', $rem_l);

          if ($add && $rem) {
            $title = pht(
              '%s edited subscriber(s), added %d: %s; removed %d: %s.',
              $author->renderLink(),
              $add_l,
              count($add),
              $rem_l,
              count($rem));
          } else if ($add) {
            $title = pht(
              '%s added %d subscriber(s): %s.',
              $author->renderLink(),
              count($add),
              $add_l);
          } else if ($rem) {
            $title = pht(
              '%s removed %d subscribers: %s.',
              $author->renderLink(),
              count($rem),
              $rem_l);
          }
          break;
        default:
          throw new Exception("Unknown transaction type '{$type}'!");
      }

      if (!$xaction_visible) {
        // Some transactions aren't useful to human viewers, like
        // the initial transactions which set the mock's name and description.
        continue;
      }

      $event = id(new PhabricatorTimelineEventView())
        ->setUserHandle($author);

      $event->setTitle($title);

      if (strlen($xaction->getComment())) {
        $event->appendChild(
          $engine->getOutput(
            $xaction,
            PholioTransaction::MARKUP_FIELD_COMMENT));
      }

      $view->addEvent($event);
    }

    return $view;
  }

}
