import os
import random
import string
import sys
from optparse import make_option

from django import db
from django.contrib.auth.models import User
from django.core.management.base import (
    BaseCommand, CommandError, NoArgsCommand )
from django.db import transaction

from reviewboard.accounts.models import Profile
from reviewboard.diffviewer.forms import UploadDiffForm
from reviewboard.diffviewer.models import DiffSetHistory
from reviewboard.reviews.models import ReviewRequest, Review, Comment
from reviewboard.scmtools.models import Repository, Tool

NORMAL = 1
DESCRIPTION_SIZE = 100
SUMMARY_SIZE = 6
LOREM_VOCAB = \
    ['Lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur',
    'Nullam', 'quis', 'erat', 'libero.', 'Ut', 'vel', 'velit', 'augue, ',
    'risus.', 'Curabitur', 'dignissim', 'luctus', 'dui, ', 'et',
    'tristique', 'id.', 'Etiam', 'blandit', 'adipiscing', 'molestie.',
    'libero', 'eget', 'lacus', 'adipiscing', 'aliquet', 'ut', 'eget',
    'urna', 'dui', 'auctor', 'id', 'varius', 'eget', 'consectetur',
    'Sed', 'ornare', 'fermentum', 'erat', 'ut', 'consectetur', 'diam',
    'in.', 'Aliquam', 'eleifend', 'egestas', 'erat', 'nec', 'semper.',
    'a', 'mi', 'hendrerit', 'vestibulum', 'ut', 'vehicula', 'turpis.',
    'habitant', 'morbi', 'tristique', 'senectus', 'et', 'netus', 'et',
    'fames', 'ac', 'turpis', 'egestas.', 'Vestibulum', 'purus', 'odio',
    'quis', 'consequat', 'non, ', 'vehicula', 'nec', 'ligula.', 'In',
    'ipsum', 'in', 'volutpat', 'ipsum.', 'Morbi', 'aliquam', 'velit',
    'molestie', 'suscipit.', 'Morbi', 'dapibus', 'nibh', 'vel',
    'justo', 'nibh', 'facilisis', 'tortor, ', 'sit', 'amet', 'dictum',
    'amet', 'arcu.', 'Quisque', 'ultricies', 'justo', 'non', 'neque',
    'nibh', 'tincidunt.', 'Curabitur', 'sit', 'amet', 'sem', 'quis',
    'vulputate.', 'Mauris', 'a', 'lorem', 'mi.', 'Donec', 'dolor',
    'interdum', 'eu', 'scelerisque', 'vel', 'massa.', 'Vestibulum',
    'risus', 'vel', 'ipsum', 'suscipit', 'laoreet.', 'Proin', 'congue',
    'blandit.', 'Aenean', 'aliquet', 'auctor', 'nibh', 'sit', 'amet',
    'Vestibulum', 'ante', 'ipsum', 'primis', 'in', 'faucibus', 'orci',
    'posuere', 'cubilia', 'Curae;', 'Donec', 'lacinia', 'tincidunt',
    'facilisis', 'nisl', 'eu', 'fermentum.', 'Ut', 'nec', 'laoreet',
    'magna', 'egestas', 'nulla', 'pharetra', 'vel', 'egestas', 'tellus',
    'Pellentesque', 'sed', 'pharetra', 'orci.', 'Morbi', 'eleifend, ',
    'interdum', 'placerat,', 'mi', 'dolor', 'mollis', 'libero',
    'quam', 'posuere', 'nisl.', 'Vivamus', 'facilisis', 'aliquam',
    'condimentum', 'pulvinar', 'egestas.', 'Lorem', 'ipsum', 'dolor',
    'consectetur', 'adipiscing', 'elit.', 'In', 'hac', 'habitasse',
    'Aenean', 'blandit', 'lectus', 'et', 'dui', 'tincidunt', 'cursus',
    'Suspendisse', 'ipsum', 'dui, ', 'accumsan', 'eget', 'imperdiet',
    'est.', 'Integer', 'porta, ', 'ante', 'ac', 'commodo', 'faucibus',
    'molestie', 'risus, ', 'a', 'imperdiet', 'eros', 'neque', 'ac',
    'nisi', 'leo', 'pretium', 'congue', 'eget', 'quis', 'arcu.', 'Cras']

NAMES = \
    ['Aaron', 'Abbey', 'Adan', 'Adelle', 'Agustin','Alan', 'Aleshia',
    'Alexia', 'Anderson', 'Ashely', 'Barbara', 'Belen', 'Bernardo',
    'Bernie', 'Bethanie', 'Bev', 'Boyd', 'Brad', 'Bret', 'Caleb',
    'Cammy', 'Candace', 'Carrol', 'Charlette', 'Charlie', 'Chelsea',
    'Chester', 'Claude', 'Daisy', 'David', 'Delila', 'Devorah',
    'Edwin', 'Elbert', 'Elisha', 'Elvis', 'Emmaline', 'Erin',
    'Eugene', 'Fausto', 'Felix', 'Foster', 'Garrett', 'Garry',
    'Garth', 'Gracie', 'Henry', 'Hertha', 'Holly', 'Homer',
    'Ileana', 'Isabella', 'Jacalyn', 'Jaime', 'Jeff', 'Jefferey',
    'Jefferson', 'Joie', 'Kanesha', 'Kassandra', 'Kirsten', 'Kymberly',
    'Lashanda', 'Lean', 'Lonnie', 'Luis', 'Malena', 'Marci', 'Margarett',
    'Marvel', 'Marvin', 'Mel', 'Melissia', 'Morton', 'Nickole', 'Nicky',
    'Odette', 'Paige', 'Patricia', 'Porsche', 'Rashida', 'Raul',
    'Renaldo', 'Rickie', 'Robbin', 'Russel', 'Sabine', 'Sabrina',
    'Sacha', 'Sam', 'Sasha', 'Shandi', 'Sherly', 'Stacey', 'Stephania',
    'Stuart', 'Talitha', 'Tanesha', 'Tena', 'Tobi', 'Tula', 'Valene',
    'Veda', 'Vikki', 'Wanda', 'Wendie', 'Wendolyn', 'Wilda', 'Wiley',
    'Willow', 'Yajaira', 'Yasmin', 'Yoshie', 'Zachariah', 'Zenia',
    'Allbert', 'Amisano', 'Ammerman', 'Androsky', 'Arrowsmith',
    'Bankowski', 'Bleakley', 'Boehringer', 'Brandstetter',
    'Capehart', 'Charlesworth', 'Danforth', 'Debernardi',
    'Delasancha', 'Denkins', 'Edmunson', 'Ernsberger', 'Faupel',
    'Florence', 'Frisino', 'Gardner', 'Ghormley', 'Harrold',
    'Hilty', 'Hopperstad', 'Hydrick', 'Jennelle', 'Massari',
    'Solinski', 'Swisher', 'Talladino', 'Tatham', 'Thornhill',
    'Ulabarro', 'Welander', 'Xander', 'Xavier', 'Xayas', 'Yagecic',
    'Yagerita', 'Yamat', 'Ying', 'Yurek', 'Zaborski', 'Zeccardi',
    'Zecchini', 'Zimerman', 'Zitzow', 'Zoroiwchak', 'Zullinger', 'Zyskowski']


class Command(NoArgsCommand):
    help = 'Populates the database with the specified fields'

    option_list = BaseCommand.option_list + (
        make_option('-u', '--users', type="int", default=None, dest='users',
            help='The number of users to add'),
        make_option('--review-requests', default=None, dest='review_requests',
            help='The number of review requests per user [min:max]'),
        make_option('--diffs', default=None, dest='diffs',
            help='The number of diff per review request [min:max]'),
        make_option('--reviews', default=None, dest='reviews',
            help='The number of reviews per diff [min:max]'),
        make_option('--diff-comments', default=None, dest='diff_comments',
            help='The number of comments per diff [min:max]'),
        make_option('-p', '--password', type="string", default=None,
            dest='password', help='The login password for users created')
        )

    @transaction.commit_on_success
    def handle_noargs(self, users=None, review_requests=None, diffs=None,
                      reviews=None, diff_comments=None, password=None,
                      verbosity=NORMAL, **options):
        num_of_requests = None
        num_of_diffs = None
        num_of_reviews = None
        num_of_diff_comments = None
        random.seed()

        if review_requests:
            num_of_requests = self.parseCommand("review_requests",
                                                review_requests)

            # Setup repository.
            repo_dir = os.path.abspath(os.path.join(sys.argv[0], "..",
                "scmtools", "testdata", "git_repo"))

            # Throw exception on error so transaction reverts.
            if not os.path.exists(repo_dir):
                raise CommandError("No path to the repository")

            self.repository = Repository.objects.create(
                name="Test Repository", path=repo_dir,
                tool=Tool.objects.get(name="Git")
                )

        if diffs:
            num_of_diffs = self.parseCommand("diffs", diffs)

            # Create the diff directory locations.
            diff_dir_tmp = os.path.abspath(os.path.join((sys.argv[0]),
                "..", "reviews", "management", "commands", "diffs"))

            # Throw exception on error so transaction reverts.
            if not os.path.exists(diff_dir_tmp):
                    raise CommandError("Diff dir does not exist")

            diff_dir = diff_dir_tmp + '/'  # Add trailing slash.

            # Get a list of the appropriate files.
            files = [f for f in os.listdir(diff_dir)
                     if f.endswith('.diff')]

            # Check for any diffs in the files.
            if len(files) == 0:
                raise CommandError("No diff files in this directory")

        if reviews:
            num_of_reviews = self.parseCommand("reviews", reviews)

        if diff_comments:
            num_of_diff_comments = self.parseCommand("diff-comments",
                                                     diff_comments)

        # Users is required for any other operation.
        if not users:
            raise CommandError("At least one user must be added")

        # Start adding data to the database.
        for i in range(1, users + 1):
            new_user = User.objects.create(
                username=self.randUsername(),  # Avoids having to flush db.
                first_name=random.choice(NAMES),
                last_name=random.choice(NAMES),
                email="test@example.com",
                is_staff=False,
                is_active=True,
                is_superuser=False)

            if password:
                new_user.set_password(password)
                new_user.save()
            else:
                new_user.set_password("test1")
                new_user.save()

            Profile.objects.create(
                user=new_user,
                first_time_setup_done=True,
                collapsed_diffs=True,
                wordwrapped_diffs=True,
                syntax_highlighting=True,
                show_submitted=True)

            # Review Requests.
            req_val = self.pickRandomValue(num_of_requests)

            if int(verbosity) > NORMAL:
                print "For user %s:%s" % (i, new_user.username)
                print "============================="

            for j in range(0, req_val):
                if int(verbosity) > NORMAL:
                    print "Request #%s:" % j

                review_request = ReviewRequest.objects.create(new_user, None)
                review_request.public = True
                review_request.summary = self.lorem_ipsum("summary")
                review_request.description = self.lorem_ipsum("description")
                review_request.shipit_count = 0
                review_request.repository = self.repository
                # Set the targeted reviewer to superuser or 1st defined.
                if j == 0:
                    review_request.target_people.add(User.objects.get(pk=1))
                review_request.save()

                # Add the diffs if any to add.
                diff_val = self.pickRandomValue(num_of_diffs)

                # If adding diffs add history.
                if diff_val > 0:
                    diffset_history = DiffSetHistory.objects.create(
                        name='testDiffFile' + str(i))
                    diffset_history.save()

                # Won't execute if diff_val is 0, ie: no diffs requested.
                for k in range(0, diff_val):
                    if int(verbosity) > NORMAL:
                        print "%s:\tDiff #%s" % (i, k)

                    random_number = random.randint(0, len(files) - 1)
                    file_to_open = diff_dir + files[random_number]
                    f = open(file_to_open, 'r')
                    form = UploadDiffForm(review_request.repository, f)
                    cur_diff = form.create(f, None, diffset_history)
                    review_request.diffset_history = diffset_history
                    review_request.save()
                    review_request.publish(new_user)
                    f.close()

                    # Add the reviews if any.
                    review_val = self.pickRandomValue(num_of_reviews)

                    for l in range(0, review_val):
                        if int(verbosity) > NORMAL:
                            print "%s:%s:\t\tReview #%s:" % (i, j, l)

                        reviews = Review.objects.create(
                            review_request=review_request,
                            user=new_user)

                        reviews.publish(new_user)

                        # Add comments if any.
                        comment_val = self.pickRandomValue(
                            num_of_diff_comments)

                        for m in range(0, comment_val):
                            if int(verbosity) > NORMAL:
                                print "%s:%s:\t\t\tComments #%s" % (i, j, m)

                            if m == 0:
                                file_diff = cur_diff.files.order_by('id')[0]

                            # Choose random lines to comment.
                            # Max lines: should be mod'd in future to read diff.
                            max_lines = 220
                            first_line = random.randrange(1, max_lines - 1)
                            remain_lines = max_lines - first_line
                            num_lines = random.randrange(1, remain_lines)

                            diff_comment = Comment.objects.create(
                                filediff=file_diff,
                                text="comment number %s" % (m + 1),
                                first_line=first_line,
                                num_lines=num_lines)

                            review_request.publish(new_user)

                            reviews.comments.add(diff_comment)
                            reviews.save()
                            reviews.publish(new_user)

                            db.reset_queries()

                        # No comments, so have previous layer clear queries.
                        if comment_val == 0:
                            db.reset_queries()

                    if review_val == 0:
                        db.reset_queries()

                if diff_val == 0:
                    db.reset_queries()

            if req_val == 0:
                db.reset_queries()

            # Generate output as users & data is created.
            if req_val != 0:
                print "user %s created with %s requests" % (
                    new_user.username, req_val)
            else:
                print "user %s created successfully" % new_user.username

    def parseCommand(self, com_arg, com_string):
        """Parse the values given in the command line."""
        try:
            return tuple((int(item.strip()) for item in com_string.split(':')))
        except ValueError:
            print >> sys.stderr, "You failed to provide \"" + com_arg \
                + "\" with one or two values of type int.\n" +\
                "Example: --" + com_arg + "=2:5"
            exit()

    def randUsername(self):
        """Used to generate random usernames so no flushing needed."""

        return ''.join(random.choice(string.ascii_lowercase)
                       for x in range(0, random.randrange(5, 9)))

    def pickRandomValue(self, value):
        """This acts like a condition check in the program, value is a tuple."""
        if not value:
            return 0

        if len(value) == 1:
            return value[0]

        return random.randrange(value[0], value[1])

    def lorem_ipsum(self, ipsum_type):
        """Create some random text for summary/description."""
        if ipsum_type == "description":
            max_size = DESCRIPTION_SIZE
        else:
            max_size = SUMMARY_SIZE

        return ' '.join(random.choice(LOREM_VOCAB)
                        for x in range(0, max_size))
