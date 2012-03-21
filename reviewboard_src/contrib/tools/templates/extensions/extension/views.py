from django.shortcuts import render_to_response
from django.template.context import RequestContext

{%- if is_configurable %}
def configure(request, template_name="{{package_name}}/configure.html"):
    return render_to_response(template_name, RequestContext(request))
{%- endif %}


{%- if dashboard_link is not none %}
def dashboard(request, template_name='{{package_name}}/dashboard.html'):
    return render_to_response(template_name, RequestContext(request))
{%- endif %}

