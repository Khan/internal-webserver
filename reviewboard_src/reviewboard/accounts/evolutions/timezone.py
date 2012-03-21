from django_evolution.mutations import AddField
from django.db import models


MUTATIONS = [
    AddField('Profile', 'timezone', models.CharField, initial=u'UTC', max_length=20)
]
