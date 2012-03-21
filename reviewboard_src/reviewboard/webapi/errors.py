from djblets.webapi.errors import WebAPIError


#
# Standard error messages
#
UNSPECIFIED_DIFF_REVISION = WebAPIError(200, "Diff revision not specified",
                                        http_status=400) # 400 Bad Request)
INVALID_DIFF_REVISION     = WebAPIError(201, "Invalid diff revision",
                                        http_status=404) # 404 Not Found
INVALID_ACTION            = WebAPIError(202, "Invalid action specified",
                                        http_status=400) # 400 Bad Request
INVALID_CHANGE_NUMBER     = WebAPIError(203, "The change number specified "
                                             "could not be found",
                                        http_status=404) # 404 Not Found
CHANGE_NUMBER_IN_USE      = WebAPIError(204, "The change number specified "
                                             "has already been used",
                                        http_status=409) # 409 Conflict
MISSING_REPOSITORY        = WebAPIError(205, "There was no repository found "
                                             "at the specified path",
                                        http_status=400) # 400 Bad Request
INVALID_REPOSITORY        = WebAPIError(206, "The repository path specified "
                                             "is not in the list of known "
                                             "repositories",
                                        http_status=400) # 400 Bad Request
REPO_FILE_NOT_FOUND       = WebAPIError(207, "The file was not found in the "
                                             "repository",
                                        http_status=400) # 400 Bad Request
INVALID_USER              = WebAPIError(208, "User does not exist",
                                        http_status=400) # 400 Bad Request
REPO_NOT_IMPLEMENTED      = WebAPIError(209, "The specified repository is "
                                             "not able to perform this action",
                                        http_status=501) # 501 Not Implemented
REPO_INFO_ERROR           = WebAPIError(210, "There was an error fetching "
                                             "extended information for this "
                                             "repository",
                                        http_status=500) # 500 Internal Server
                                                         #     Error
NOTHING_TO_PUBLISH        = WebAPIError(211, "You attempted to publish a "
                                             "review request that doesn't "
                                             "have an associated draft",
                                        http_status=400) # 400 Bad Request
EMPTY_CHANGESET           = WebAPIError(212, "The change number specified "
                                             "represents an empty changeset",
                                        http_status=400) # 400 Bad Request
SERVER_CONFIG_ERROR       = WebAPIError(213, "There was an error storing "
                                             "configuration on the server",
                                        http_status=500) # 500 Internal Server
                                                         #     Error
BAD_HOST_KEY              = WebAPIError(214, "The SSH key on the host does "
                                             "not match the stored key",
                                        http_status=403) # 403 Forbidden
UNVERIFIED_HOST_KEY       = WebAPIError(215, "The SSH key on the host is "
                                             "unverified",
                                        http_status=403) # 403 Forbidden
UNVERIFIED_HOST_CERT      = WebAPIError(216, "The HTTPS certificate on the "
                                             "host is unverified",
                                        http_status=403) # 403 Forbidden
MISSING_USER_KEY          = WebAPIError(217, "A public SSH key was requested, "
                                             "but no SSH key was available "
                                             "to send",
                                        http_status=403) # 403 Forbidden
REPO_AUTHENTICATION_ERROR = WebAPIError(218, "Unable to authenticate with "
                                             "the repository using the "
                                             "provided credentials",
                                        http_status=403) # 403 Forbidden
