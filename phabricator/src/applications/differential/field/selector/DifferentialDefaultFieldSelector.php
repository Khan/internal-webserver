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

final class DifferentialDefaultFieldSelector
  extends DifferentialFieldSelector {

  public function getFieldSpecifications() {
    $fields = array(
      new DifferentialTitleFieldSpecification(),
      new DifferentialSummaryFieldSpecification(),
    );

    if (PhabricatorEnv::getEnvConfig('differential.show-test-plan-field')) {
      $fields[] = new DifferentialTestPlanFieldSpecification();
    }

    $fields = array_merge(
      $fields,
      array(
        new DifferentialRevisionStatusFieldSpecification(),
        new DifferentialAuthorFieldSpecification(),
        new DifferentialReviewersFieldSpecification(),
        new DifferentialReviewedByFieldSpecification(),
        new DifferentialCCsFieldSpecification(),
        new DifferentialLintFieldSpecification(),
        new DifferentialUnitFieldSpecification(),
        new DifferentialCommitsFieldSpecification(),
        new DifferentialDependenciesFieldSpecification(),
        new DifferentialManiphestTasksFieldSpecification(),
      ));

    if (PhabricatorEnv::getEnvConfig('differential.show-host-field')) {
      $fields[] = new DifferentialHostFieldSpecification();
      $fields[] = new DifferentialPathFieldSpecification();
    }

    $fields = array_merge(
      $fields,
      array(
        new DifferentialBranchFieldSpecification(),
        new DifferentialArcanistProjectFieldSpecification(),
        new DifferentialApplyPatchFieldSpecification(),
        new DifferentialRevisionIDFieldSpecification(),
        new DifferentialGitSVNIDFieldSpecification(),
        new DifferentialDateModifiedFieldSpecification(),
        new DifferentialDateCreatedFieldSpecification(),
        new DifferentialAuditorsFieldSpecification(),
      ));

    return $fields;
  }

  public function sortFieldsForRevisionList(array $fields) {
    assert_instances_of($fields, 'DifferentialFieldSpecification');

    $map = array();
    foreach ($fields as $field) {
      $map[get_class($field)] = $field;
    }

    $map = array_select_keys(
      $map,
      array(
        'DifferentialRevisionIDFieldSpecification',
        'DifferentialTitleFieldSpecification',
        'DifferentialRevisionStatusFieldSpecification',
        'DifferentialAuthorFieldSpecification',
        'DifferentialReviewersFieldSpecification',
        'DifferentialDateModifiedFieldSpecification',
        'DifferentialDateCreatedFieldSpecification',
      )) + $map;

    return array_values($map);
  }

  public function sortFieldsForMail(array $fields) {
    assert_instances_of($fields, 'DifferentialFieldSpecification');

    $map = array();
    foreach ($fields as $field) {
      $map[get_class($field)] = $field;
    }

    $map = array_select_keys(
      $map,
      array(
        'DifferentialReviewersFieldSpecification',
        'DifferentialSummaryFieldSpecification',
        'DifferentialTestPlanFieldSpecification',
        'DifferentialRevisionIDFieldSpecification',
        'DifferentialManiphestTasksFieldSpecification',
        'DifferentialBranchFieldSpecification',
        'DifferentialArcanistProjectFieldSpecification',
        'DifferentialCommitsFieldSpecification',
      )) + $map;

    return array_values($map);
  }

}

