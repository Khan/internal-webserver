# gae_dashboard

This folder contains various miscellaneous script that runs on Toby server.

## Setup

To run these scripts locally, you will need to install your local python dependencies using
`pipenv --two install --dev`.

Make sure to also install submodules in internal-webserver using
`git submodule update --init`

### Secrets

In order to run the scripts (e.g. send message to Slack), there needs to be a `secrets.py`.

All secrets can be found in
[keeper](https://keepersecurity.com/vault/#).

The secrets you will need depends on what system you will to talk to.

* For `alertlib`, you will need to setup `slack_alertlib_webhook_url` in `secrets.py` - the secret is at: https://keepersecurity.com/vault/#detail/RGXygKfytLDV9dnJR6DPJA
