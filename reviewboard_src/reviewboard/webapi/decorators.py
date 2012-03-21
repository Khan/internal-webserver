from django.http import HttpRequest
from djblets.siteconfig.models import SiteConfiguration
from djblets.util.decorators import simple_decorator
from djblets.webapi.core import WebAPIResponse, WebAPIResponseError
from djblets.webapi.decorators import webapi_login_required, \
                                      webapi_response_errors, \
                                      _find_httprequest
from djblets.webapi.encoders import BasicAPIEncoder
from djblets.webapi.errors import DOES_NOT_EXIST, NOT_LOGGED_IN, \
                                  PERMISSION_DENIED

from reviewboard.site.models import LocalSite


@webapi_response_errors(NOT_LOGGED_IN)
@simple_decorator
def webapi_check_login_required(view_func):
    """
    A decorator that checks whether login is required on this installation
    and, if so, checks if the user is logged in. If login is required and
    the user is not logged in, they'll get a NOT_LOGGED_IN error.
    """
    def _check(*args, **kwargs):
        siteconfig = SiteConfiguration.objects.get_current()
        request = _find_httprequest(args)

        if (siteconfig.get("auth_require_sitewide_login") or
            (request.user.is_anonymous() and
             'HTTP_AUTHORIZATION' in request.META)):
            return webapi_login_required(view_func)(*args, **kwargs)
        else:
            return view_func(*args, **kwargs)

    view_func.checks_login_required = True

    return _check


def webapi_deprecated(deprecated_in, force_error_http_status=None,
                      default_api_format=None, encoders=[]):
    """Marks an API handler as deprecated.

    ``deprecated_in`` specifies the version that first deprecates this call.

    ``force_error_http_status`` forces errors to use the specified HTTP
    status code.

    ``default_api_format`` specifies the default api format (json or xml)
    if one isn't provided.
    """
    def _dec(view_func):
        def _view(*args, **kwargs):
            if default_api_format:
                request = args[0]
                assert isinstance(request, HttpRequest)

                method_args = getattr(request, request.method, None)

                if method_args and 'api_format' not in method_args:
                    method_args = method_args.copy()
                    method_args['api_format'] = default_api_format
                    setattr(request, request.method, method_args)

            response = view_func(*args, **kwargs)

            if isinstance(response, WebAPIResponse):
                response.encoders = encoders

            if isinstance(response, WebAPIResponseError):
                response.api_data['deprecated'] = {
                    'in_version': deprecated_in,
                }

                if (force_error_http_status and
                    isinstance(response, WebAPIResponseError)):
                    response.status_code = force_error_http_status

            return response

        return _view

    return _dec


_deprecated_api_encoders = []

def webapi_deprecated_in_1_5(view_func):
    from reviewboard.webapi.encoder import DeprecatedReviewBoardAPIEncoder
    global _deprecated_api_encoders

    if not _deprecated_api_encoders:
        _deprecated_api_encoders = [
            DeprecatedReviewBoardAPIEncoder(),
            BasicAPIEncoder(),
        ]

    return webapi_deprecated(
        deprecated_in='1.5',
        force_error_http_status=200,
        default_api_format='json',
        encoders=_deprecated_api_encoders)(view_func)


@webapi_response_errors(DOES_NOT_EXIST, NOT_LOGGED_IN, PERMISSION_DENIED)
@simple_decorator
def webapi_check_local_site(view_func):
    """Checks whether a user has access to a local site given in the URL.

    This decorator can be added to get/get_list methods to check whether or
    not a user should be able to view them given the local site name in the URL.
    """
    def _check(*args, **kwargs):
        request = _find_httprequest(args)
        local_site_name = kwargs.get('local_site_name', None)
        if local_site_name:
            try:
                local_site = LocalSite.objects.get(name=local_site_name)

                if not local_site.is_accessible_by(request.user):
                    if request.user.is_authenticated():
                        return PERMISSION_DENIED
                    else:
                        return NOT_LOGGED_IN
            except LocalSite.DoesNotExist:
                return DOES_NOT_EXIST

        return view_func(*args, **kwargs)

    return _check
