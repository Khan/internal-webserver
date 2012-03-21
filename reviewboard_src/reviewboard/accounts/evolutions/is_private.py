from django_evolution.mutations import AddField
from django.db import models


MUTATIONS = [
    AddField('Profile', 'is_private', models.BooleanField, initial=False)
]
