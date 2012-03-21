from djblets.extensions.base import ExtensionHook, ExtensionHookPoint
import djblets.extensions.hooks as djblets_hooks


class DashboardHook(ExtensionHook):
    __metaclass__ = ExtensionHookPoint

    def __init__(self, extension, entries=[], *args, **kwargs):
        super(DashboardHook, self).__init__(extension, *args, **kwargs)
        self.entries = entries


class NavigationBarHook(ExtensionHook):
    """A hook for adding entries to the main navigation bar.

    This takes a list of entries. Each entry represents something
    on the navigation bar, and is a dictionary with the following keys:

        * ``label``:    The label to display
        * ``url``:      The URL to point to.
        * ``url_name``: The name of the URL to point to.

    Only one of ``url`` or ``url_name`` is required. ``url_name`` will
    take precedence.

    If your hook needs to access the template context, it can override
    get_entries and return results from there.
    """
    __metaclass__ = ExtensionHookPoint

    def __init__(self, extension, entries={}, *args, **kwargs):
        super(NavigationBarHook, self).__init__(extension, *args,
                                                **kwargs)
        self.entries = entries

    def get_entries(self, context):
        return self.entries


class ReviewRequestDetailHook(ExtensionHook):
    __metaclass__ = ExtensionHookPoint

    def get_field_id(self):
        raise NotImplementedError

    def get_label(self):
        raise NotImplementedError

    def get_detail(self):
        raise NotImplementedError

    def get_wide(self):
        """Returns whether or not this detail spans multiple columns."""
        return False


class ActionHook(ExtensionHook):
    """A hook for adding actions to a review request.

    Actions are displayed somewhere on the action bar (alongside Reviews,
    Close, etc.) of the review request. The subclasses of ActionHook should
    be used to determine placement.

    The provided actions parameter must be a list of actions. Each
    action must be a dict with the following keys:

       * `id`:           The ID of this action (optional).
       * `image`:        The path to the image used for the icon (optional).
       * `image_width`:  The width of the image (optional).
       * `image_height`: The height of the image (optional).
       * `label`:        The label for the action.
       * `url`:          The URI to invoke when the action is clicked.
                         This should not be a javascript: URL, as that won't
                         work on all browsers.

    If your hook needs to access the template context, it can override
    get_actions and return results from there.
    """
    def __init__(self, extension, actions={}, *args, **kwargs):
        super(ActionHook, self).__init__(extension, *args, **kwargs)
        self.actions = actions

    def get_actions(self, context):
        """Returns the list of action information for this action."""
        return self.actions


class ReviewRequestActionHook(ActionHook):
    """A hook for adding an action to the review request page."""
    __metaclass__ = ExtensionHookPoint


class ReviewRequestDropdownActionHook(ActionHook):
    """A hook for adding an drop down action to the review request page.

    The actions for a drop down action should contain:

       * `id`:      The ID of this action (optional).
       * `label`:   The label of the drop-down.
       * `items`:   A list of ActionHook-style dicts (see ActionHook params).

    For example::

        actions = [{
            'id': 'id 0',
            'label': 'Title',
            'items': [
                {
                    'id': 'id 1',
                    'label': 'Item 1',
                    'url': '...',
                },
                {
                    'id': 'id 2',
                    'label': 'Item 2',
                    'url': '...',
                }
            ]
        }]
    """
    __metaclass__ = ExtensionHookPoint


class DiffViewerActionHook(ActionHook):
    """A hook for adding an action to the diff viewer page."""
    __metaclass__ = ExtensionHookPoint


URLHook = djblets_hooks.URLHook
TemplateHook = djblets_hooks.TemplateHook
