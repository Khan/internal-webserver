"""Utilities for querying request logs for DOS alerts.

In the past we queried request logs simply from a streaming table,
`logs_hourly.requestlogs_*`. However, we are deprecating that in favour
of a daily log processing job, which means that we won't always have the
latest logs available. To get around this, we query and process and
pre-processed request protobuf payloads. The query is a striped down version of
https://phabricator.khanacademy.org/D47305, and also assumes that the DOS
alert's period is very much under a day (for simplicity).

To query historical request logs, query directly from `logs.requestlogs_*`.
"""

import datetime


# NOTE(yanske1): this dataset is stored in the "other" Khan project, which
# you'll probably need to request view access to in #it!
_PROTOLOGS_PROJECT = 'khan-academy'
_PROTOLOGS_DATASET = 'logs_raw_and_recent'

_LOGSTREAM_PROJECT = 'khanacademy.org:deductive-jet-827'
_LOGSTREAM_DATASET = 'log_streams'


def _table_since_duration(project, dataset, table_prefix, period):
    """Returns table name(s) to query from given the period for the logs.

    Previously we simply query from the hourly request logs table, which was
    around 3GB. Querying from the raw, unprocessed logs table will be around
    100GB. For a script that runs every 5 minutes, this increases the cost
    significantly.

    To address this, we can assume that all logs timestamped at any given
    moment will arrive at the logs table within 5 mins. Then, we use table
    decorators to reduce the size of the table. A side effect of this is that
    we will have to use legacy SQL, and we can no longer use wild card matching
    on table names.
    """

    _MAX_LOG_DELAY_MS = 5 * 60 * 1000

    _TABLE_DATE_FORMAT = '%Y%m%d'
    _TABLE_STRING_TEMPLATE = """
        [{project}.{dataset}.{table_prefix}_{table_date}@-{latest_duration}-]
    """

    end = datetime.datetime.utcnow()
    start = end - datetime.timedelta(seconds=period)

    # If a period goes into the previous day, we would also have to
    # consider the log tables from that day as well.
    overlapped_table_dates = [end] if end.day == start.day else [end, start]

    return ', '.join([
        _TABLE_STRING_TEMPLATE.format(
            project=project,
            dataset=dataset,
            table_prefix=table_prefix,
            table_date=table_date.strftime(_TABLE_DATE_FORMAT),
            latest_duration=(period * 1000 + _MAX_LOG_DELAY_MS)
        ) for table_date in overlapped_table_dates
    ])


def _dos_logs_from_standard_query(period):
    _STANDARD_LOG_TABLE_PREFIX = 'appengine_googleapis_com_request_log'
    source_table = _table_since_duration(_PROTOLOGS_PROJECT,
                                         _PROTOLOGS_DATASET,
                                         _STANDARD_LOG_TABLE_PREFIX, period)

    return """
        SELECT
            FIRST(protoPayload.ip) AS ip,
            MIN(protoPayload.startTime) AS start_time_timestamp,
            FIRST(protoPayload.resource) AS resource,
            FIRST(protoPayload.userAgent) AS user_agent,
            FIRST(protoPayload.moduleId) AS module_id,
            protoPayload.requestId AS request_id
        FROM
            {source_table}
        GROUP BY
            request_id
    """.format(source_table=source_table)


def _dos_logs_from_flex_query(period):
    """Gets request logs from App Engine Flex environments from 'period' ago.

    Some of our content-editing services live in Flex, and we want to fetch
    logs and join them with our App Engine Standard logs.
    """

    _FLEX_LOG_TABLE_PREFIX = 'appengine_googleapis_com_nginx_request'
    source_table = _table_since_duration(_PROTOLOGS_PROJECT,
                                         _PROTOLOGS_DATASET,
                                         _FLEX_LOG_TABLE_PREFIX, period)

    return """
        SELECT
            FIRST(httpRequest.remoteIp) AS ip,
            MAX(timestamp) AS end_time_timestamp,
            FIRST(httpRequest.requestUrl) AS resource,
            FIRST(httpRequest.userAgent) AS user_agent,
            FIRST(resource.labels.module_id) AS module_id,
            MAX(FLOAT(jsonPayload.latencySeconds)) AS latency,
            labels.appengine_googleapis_com_trace_id AS request_id
        FROM
            {source_table}
        GROUP BY request_id
    """.format(source_table=source_table)


def _dos_bingo_conversions_query(period):
    _BINGO_CONVERSIONS_TABLE_PREFIX = 'bingo_conversion_event'
    source_table = _table_since_duration(_LOGSTREAM_PROJECT,
                                         _LOGSTREAM_DATASET,
                                         _BINGO_CONVERSIONS_TABLE_PREFIX,
                                         period)

    return """
        SELECT
            NEST(
                conversion
            ) AS bingo_conversion_events,
            MIN(timestamp) AS start_time_timestamp,
            request_id
        FROM (
            SELECT
                FIRST(info.timestamp) AS timestamp,
                FIRST(info.request_id) AS request_id,
                FIRST(conversion) AS conversion
            FROM
                {source_table}
            GROUP BY info.id
        )
        GROUP BY request_id
    """.format(source_table=source_table)


def _dos_kalogs_query(period):
    _KALOGS_TABLE_PREFIX = 'kalog'
    source_table = _table_since_duration(_LOGSTREAM_PROJECT,
                                         _LOGSTREAM_DATASET,
                                         _KALOGS_TABLE_PREFIX, period)

    return """
        SELECT
            FIRST(client_ip) AS elog_client_ip,
            FIRST(user_kaid) AS elog_user_kaid,
            MAX(info.timestamp) AS timestamp,
            info.request_id AS request_id
        FROM
            {source_table}
        GROUP BY
            request_id
    """.format(source_table=source_table)


def latest_logs_for_ddos_query(period):
    return """
        SELECT
            COALESCE(
                standard_logs.ip,
                flex_logs.ip,
                ka_logs.elog_client_ip
            ) AS ip,
            COALESCE(
                standard_logs.start_time_timestamp,
                SEC_TO_TIMESTAMP(
                    TIMESTAMP_TO_SEC(flex_logs.end_time_timestamp) -
                    INTEGER(flex_logs.latency)
                ),
                ka_logs.timestamp,
                bingo_conversions.start_time_timestamp
            ) AS start_time_timestamp,
            COALESCE(standard_logs.resource, flex_logs.resource) AS resource,
            COALESCE(
                standard_logs.user_agent, flex_logs.user_agent) AS user_agent,
            COALESCE(
                standard_logs.module_id,
                flex_logs.module_id
            ) AS module_id,
            ka_logs.elog_user_kaid AS elog_user_kaid,
            bingo_conversions.bingo_conversion_events
                AS bingo_conversion_events
        FROM (
                {standard_logs_query}) standard_logs
            FULL OUTER JOIN EACH (
                {flex_logs_query}) flex_logs
            ON (standard_logs.request_id = flex_logs.request_id)
            FULL OUTER JOIN EACH (
                {kalogs_query}) ka_logs
            ON (flex_logs.request_id = ka_logs.request_id)
            FULL OUTER JOIN EACH (
                {bingo_conversions_query}) bingo_conversions
            ON (ka_logs.request_id = bingo_conversions.request_id)
    """.format(
        standard_logs_query=_dos_logs_from_standard_query(period),
        flex_logs_query=_dos_logs_from_flex_query(period),
        kalogs_query=_dos_kalogs_query(period),
        bingo_conversions_query=_dos_bingo_conversions_query(period)
    )
