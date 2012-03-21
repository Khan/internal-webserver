from django.conf.urls.defaults import patterns, url

urlpatterns = patterns('reviewboard.reviews.views',
    url(r'^$', 'all_review_requests', name="all-review-requests"),

    # Review request creation
    url(r'^new/$', 'new_review_request', name="new-review-request"),

    # Review request detail
    url(r'^(?P<review_request_id>[0-9]+)/$', 'review_detail',
        name="review-request-detail"),

    # Reviews
    (r'^(?P<review_request_id>[0-9]+)/reviews/draft/inline-form/$',
     'review_draft_inline_form',
     {'template_name': 'reviews/review_draft_inline_form.html'}),

    # Review request diffs
    url(r'^(?P<review_request_id>[0-9]+)/diff/$', 'diff', name="view_diff"),
    url(r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)/$', 'diff',
        name="view_diff_revision"),

    url(r'^(?P<review_request_id>[0-9]+)/diff/raw/$', 'raw_diff',
        name='raw_diff'),
    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)/raw/$',
     'raw_diff'),

    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)/fragment/(?P<filediff_id>[0-9]+)/$',
     'diff_fragment'),
    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)/fragment/(?P<filediff_id>[0-9]+)/chunk/(?P<chunkindex>[0-9]+)/$',
     'diff_fragment'),

    # Fragments
    (r'^(?P<review_request_id>[0-9]+)/fragments/diff-comments/(?P<comment_ids>[0-9,]+)/$',
     'comment_diff_fragments'),

    # Review request interdiffs
    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)-(?P<interdiff_revision>[0-9]+)/$',
     'diff'),
    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)-(?P<interdiff_revision>[0-9]+)/fragment/(?P<filediff_id>[0-9]+)/$',
     'diff_fragment'),
    (r'^(?P<review_request_id>[0-9]+)/diff/(?P<revision>[0-9]+)-(?P<interdiff_revision>[0-9]+)/fragment/(?P<filediff_id>[0-9]+)/chunk/(?P<chunkindex>[0-9]+)/$',
     'diff_fragment'),

    # Screenshots
    url(r'^(?P<review_request_id>[0-9]+)/s/(?P<screenshot_id>[0-9]+)/$',
        'view_screenshot',
        name='screenshot'),

    # E-mail previews
    (r'^(?P<review_request_id>[0-9]+)/preview-email/(?P<format>(text|html))/$',
     'preview_review_request_email'),
    (r'^(?P<review_request_id>[0-9]+)/changes/(?P<changedesc_id>[0-9]+)/preview-email/(?P<format>(text|html))/$',
     'preview_review_request_email'),
    (r'^(?P<review_request_id>[0-9]+)/reviews/(?P<review_id>[0-9]+)/preview-email/(?P<format>(text|html))/$',
     'preview_review_email'),
    (r'^(?P<review_request_id>[0-9]+)/reviews/(?P<review_id>[0-9]+)/replies/(?P<reply_id>[0-9]+)/preview-email/(?P<format>(text|html))/$',
     'preview_reply_email'),

    # Search
    url(r'^search/$', 'search', name="search"),
)

