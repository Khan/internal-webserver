from django_evolution.mutations import AddField
from django.db import models


MUTATIONS = [
    AddField('Group', 'invite_only', models.BooleanField, initial=False),
]
