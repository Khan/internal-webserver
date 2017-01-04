#!/usr/bin/env python

"""Fetch real-time Google App Engine cost data from an unoffical JSON API.

This code is not production ready, or even lint-passing. See the many comments
to understand what work remains, in addition to more docs, of course.

However, with a valid khan academy email and password,
this does get the relevant JSON as a quick proof of concept.
"""

import requests
import selenium.webdriver
import selenium.webdriver.common.by
import selenium.webdriver.support.expected_conditions as EC
import selenium.webdriver.support.ui


def login_and_return_cookies():
    driver = selenium.webdriver.Chrome()

    # is prod-deploy the right email? https://phabricator.khanacademy.org/K43
    # my informal testing was with my own email
    mail_address = 'TODO'
    # putting the password in this script is only to test this general approach
    # for a real model, see credentials approach in old scraping code:
    # https://github.com/Khan/internal-webserver/commit/51f095b124937481e0debc1dc09c7b2ff9ac7ba3#diff-2c7f3a6d0f622d1e24d9479e7cb7dc89L5
    password = 'dont commit me to git!'

    login_url = 'https://accounts.google.com/ServiceLogin'
    driver.get(login_url)

    driver.find_element_by_id("Email").send_keys(mail_address)
    driver.find_element_by_id("next").click()

    # wait up to 10 sec for password field to appear after we submit email
    selenium.webdriver.support.ui.WebDriverWait(driver, 10).until(
        EC.presence_of_element_located(
            (selenium.webdriver.common.by.By.ID, "Passwd")))
    driver.find_element_by_id("Passwd").send_keys(password)
    driver.find_element_by_id("signIn").click()

    # may also need to deal with 2FA depending on email address

    # besides login cookies, we also need console.cloud.google.com cookies
    dashboard_url = ('https://console.cloud.google.com/appengine?src=ac'
                     '&project=khan-academy&duration=P1D&graph=AE_INSTANCES')
    driver.get(dashboard_url)

    return driver.get_cookies()


def get_billing_json(cookies):
    json_url = ('https://console.cloud.google.com/'
                'm/gae/dashboard/billing?pid=khan-academy')

    # format selenium-derived cookie dict for the requests library
    my_cookies = dict([(c['name'], c['value']) for c in cookies])

    response = requests.get(json_url, cookies=my_cookies)

    # before the parseable json, the response includes a line like:
    # )]}'
    # would want to drop that and then use json.loads to get a python dict
    return response.text


def main():
    cookies = login_and_return_cookies()
    print get_billing_json(cookies)
    # for production, the idea is to use a long running selenium session
    # to avoid frequent logins leading to a captcha. see slack discussion:
    # https://khanacademy.slack.com/archives/wtf/p1483567885000008
    # so unlike the other scripts which are expected to be run as cron jobs,
    # this one would be running all the time, restarted when necessary
    # by a retry-daemon or something along those lines.
    # so, the main function should actually call get_billing_json every few min
    # and crash with an exception when it can't fetch the json,
    # so that it can be restarted and get fresh cookies.

    # besides finishing the TODOs in this file,
    # there's the matter of getting xvfb-run set up so this can work headlessly
    # which also necessitates installing chrome on toby
    # so this also requires changes to the aws-config repo -- see toby/setup.sh


if __name__ == '__main__':
    # would actually set this up as a command line program with argparse
    main()
