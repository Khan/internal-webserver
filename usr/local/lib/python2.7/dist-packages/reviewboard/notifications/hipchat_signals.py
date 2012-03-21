import logging

import hipchat.room
import hipchat.config
from django.template.loader import render_to_string
from django.contrib.sites.models import Site
from djblets.siteconfig.models import SiteConfiguration

from reviewboard.accounts.signals import user_registered
from reviewboard.reviews.models import ReviewRequest, Review
from reviewboard.reviews.signals import review_request_published, \
                                        review_published, reply_published
from reviewboard.reviews.views import build_diff_comment_fragments

def send_hipchat_message_to_rooms(room_names, message, color):
    """
    Sends a message to a list of rooms, with a given background color.
    """
    room_list = [room for room in hipchat.room.Room.list()
                 if room.name in room_names]

    if room_list:
        for room in room_list:
            msg_dict = {
                "room_id": room.room_id,
                "from": "ReviewBoard",
                "notify": 1,
                "message": message,
                "color": color
            }
            hipchat.room.Room.message(**msg_dict)
    else:
        logging.error("Room not found: %s" % room_name)


def format_and_send_hipchat_message(user, sender, review_request, template_name,
                                    color, context):
    """
    Formats a message using a template + context object and sends it via HipChat.
    """
    current_site = Site.objects.get_current()
    siteconfig = current_site.config.get()
    domain_method = siteconfig.get("site_domain_method")

    context['domain'] = current_site.domain
    context['domain_method'] = domain_method
    context['review_request'] = review_request

    text_body = render_to_string(template_name, context)

    recipients = set()

    recipients.add(user.username)

    if review_request.submitter.is_active:
        recipients.add(review_request.submitter.username)

    for u in review_request.target_people.filter(is_active=True):
        recipients.add(u.username)

    for profile in review_request.starred_by.all():
        if profile.user.is_active:
            recipients.add(profile.user.username)

    # Filter out the sender so you don't get verbose notifications for
    # your own actions.
    room_names = ["ReviewBoard: %s" % username for username in recipients
                  if username != sender.username]
    send_hipchat_message_to_rooms(room_names, text_body, color)

    # However, do give a short (one-line) notification, so you have a
    # record of what you've done.  This is the only time we use yellow.
    if sender.username in recipients:
        send_hipchat_message_to_rooms("ReviewBoard: %s" % sender.username,
                                      text_body.split("\n", 2)[0],
                                      "yellow")

def send_hipchat_review_request(user, review_request, changedesc=None):
    """
    Send a hipchat message representing the supplied review request.
    """
    # If the review request is not yet public or has been discarded, don't send
    # any mail.
    if not review_request.public or review_request.status == 'D':
        return

    extra_context = {}

    # We're assuming that the existence of a changedesc implies this is not
    # a new review request.
    if changedesc:
        extra_context['status'] = "Updated"
        extra_context['change_text'] = changedesc.text
        extra_context['changes'] = changedesc.fields_changed
        color = "purple"
    else:
        extra_context['status'] = "New"
        color = "red"

    format_and_send_hipchat_message(user,
                                    review_request.submitter,
                                    review_request,
                                    'notifications/review_request_hipchat.txt',
                                    color,
                                    extra_context)

def send_hipchat_review(user, review):
    """
    Send a hipchat message representing the supplied review.
    """
    review_request = review.review_request

    if not review_request.public:
        return

    review.ordered_comments = \
        review.comments.order_by('filediff', 'first_line')

    extra_context = {
        'user': user,
        'review': review,
    }

    has_error, extra_context['comment_entries'] = \
        build_diff_comment_fragments(
            review.ordered_comments, extra_context,
            "notifications/email_diff_comment_fragment.html")

    if review.ship_it:
        color = "green"
    else:
        color = "purple"

    format_and_send_hipchat_message(user,
                                    review.user,
                                    review_request,
                                    'notifications/review_hipchat.txt',
                                    color,
                                    extra_context)

def send_hipchat_reply(user, reply):
    """
    Send a hipchat message representing the supplied reply.
    """
    review = reply.base_reply_to
    review_request = review.review_request

    if not review_request.public:
        return

    extra_context = {
        'user': user,
        'review': review,
        'reply': reply,
    }

    has_error, extra_context['comment_entries'] = \
        build_diff_comment_fragments(
            reply.comments.order_by('filediff', 'first_line'),
            extra_context,
            "notifications/email_diff_comment_fragment.html")

    format_and_send_hipchat_message(user,
                                    reply.user,
                                    review_request,
                                    'notifications/reply_hipchat.txt',
                                    "purple",
                                    extra_context)


def review_request_published_cb(sender, user, review_request, changedesc,
                                **kwargs):
    """
    Listens to the ``review_request_published`` signal and sends a
    hipchat notification if this type of notification is enabled (through
    ``hipchat_send_review_notification`` site configuration).
    """
    siteconfig = SiteConfiguration.objects.get_current()
    if siteconfig.get("hipchat_send_review_notification"):
        send_hipchat_review_request(user, review_request, changedesc)

def review_published_cb(sender, user, review, **kwargs):
    """
    Listens to the ``review_published`` signal and sends a
    hipchat notification if this type of notification is enabled (through
    ``hipchat_send_review_notification`` site configuration).
    """
    siteconfig = SiteConfiguration.objects.get_current()
    if siteconfig.get("hipchat_send_review_notification"):
        send_hipchat_review(user, review)


def reply_published_cb(sender, user, reply, **kwargs):
    """
    Listens to the ``reply_published`` signal and sends a
    hipchat notification if this type of notification is enabled (through
    ``hipchat_send_review_notification`` site configuration).
    """
    siteconfig = SiteConfiguration.objects.get_current()
    if siteconfig.get("hipchat_send_review_notification"):
        send_hipchat_reply(user, reply)

def connect_signals():
    """
    Hooks up all the event handlers to our callbacks above.
    """
    review_request_published.connect(review_request_published_cb,
                                     sender=ReviewRequest)
    review_published.connect(review_published_cb, sender=Review)
    reply_published.connect(reply_published_cb, sender=Review)

    hipchat.config.init_cfg('/home/ubuntu/reviewboard/hipchat.cfg')


