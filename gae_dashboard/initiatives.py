"""Initiatives

"""
import re


INFRA = {'title': 'Infrastructure',
         'email': 'infrastructure-blackhole@khanacademy.org',
         'id': 'infra'}
CCL = {'title': 'Coaching and Coached Learning',
       'email': 'coached-perf-reports@khanacademy.org',
       'id': 'ccl'}
IL = {'title': 'Independent Learning',
      'email': 'independent-learning-blackhole@khanacademy.org',
      'id': 'il'}
TEST_PREP = {'title': 'Test Preparation',
             'email': 'testprep-blackhole@khanacademy.org',
             'id': 'testprep'}
CP = {'title': 'Content Platform',
      'email': 'content-platform-blackhole@khanacademy.org',
      'id': 'cp'}

_INITIATIVES = (INFRA, CCL, IL, TEST_PREP, CP)


def email(id):
    for initiative in _INITIATIVES:
        if initiative['id'] == id:
            return initiative['email']
    return None


def title(id):
    for initiative in _INITIATIVES:
        if initiative['id'] == id:
            return initiative['title']
    return None


# These regular expressions are evaluated in order and the first match is used.
_route_owner_pats = (
    (r'/sat/', TEST_PREP),
    (r'/test_prep/', TEST_PREP),
    (r'sat-', TEST_PREP),
    (r'test-prep', TEST_PREP),
    (r'getSat', TEST_PREP),

    (r'getTopics', CCL),
    (r'Assignment', CCL),
    (r'Coach', CCL),
    (r'Class', CCL),
    (r'Student', CCL),
    (r'School', CCL),
    (r'LearnStorm', CCL),
    (r'/eduorgs/', CCL),
    (r'coach', CCL),
    (r'getExerciseContentReport', CCL),
    (r'requestinvitestudents', CCL),
    (r'/students/', CCL),
    (r'classroom', CCL),
    (r'learnstorm', CCL),
    (r'students', CCL),
    (r'getTopicChildren', CCL),

    (r'/dev/edit/', CP),
    (r'/dev/publish', CP),
    (r'/dev/author_details/', CP),
    (r'translator', CP),
    (r'locales', CP),
    (r'/dev/staged', CP),
    (r'/api/internal/permissions', CP),

    (r'deferred_middleware', INFRA),
    (r'mapreduce_app', INFRA),
    (r'/pubsub/', INFRA),
    (r'/auth2/', INFRA),
    (r'/api/internal/user/capabilities', INFRA),
    (r'/api/internal/devadmin', INFRA),
    (r'/prime', INFRA),
    (r'/_ah/', INFRA),
    (r'/_?pipeline/', INFRA),
)

# Like route owners but for filenames in webapp. Currently used to match react
# component jsx files.
_package_owner_pats = (
    (r'/test-prep', TEST_PREP),
    (r'/sat', TEST_PREP),

    (r'/assignments', CCL),
    (r'/coach', CCL),
    (r'/class-', CCL),
    (r'/eduorg-', CCL),
    (r'/learnstorm', CCL),
    (r'/student', CCL),

    (r'/editor', CP),
    (r'/translation', CP),
    (r'/perseus', CP),
    (r'-editor', CP),

    (r'/devadmin-package', INFRA),
    (r'/zero-rating', INFRA),
    (r'/content-permissions', INFRA),
)


def route_owner(route):
    """Return an initiative give a route (as reported by ) elog route."""
    for pat, initiative in _route_owner_pats:
        if re.search(pat, route):
            return initiative

    # If no patterns match then return IL
    return IL


def package_owner(package):
    """Return an initiative given a package filename"""
    for pat, initiative in _package_owner_pats:
        if re.search(pat, package):
            return initiative

    # If no patterns match then return IL
    return IL
