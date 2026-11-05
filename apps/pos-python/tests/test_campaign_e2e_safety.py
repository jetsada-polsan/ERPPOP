"""Keep the campaign acceptance runner's HTTP traffic inside the local machine."""
import importlib.util
from pathlib import Path
from urllib.request import Request, build_opener

import pytest

spec = importlib.util.spec_from_file_location(
    'campaign_e2e', Path(__file__).resolve().parents[1] / 'e2e/pos_campaigns_to_laravel.py')
campaign = importlib.util.module_from_spec(spec)
spec.loader.exec_module(campaign)


@pytest.mark.parametrize('url', [
    'https://erp.popstarcenter.com', 'http://27.254.143.219',
    'http://127.0.0.1.example.com', 'http://localhost',
    'https://127.0.0.1:8123', 'http://user:secret@127.0.0.1:8123',
])
def test_runner_refuses_external_requests_and_redirects(url):
    with pytest.raises(ValueError, match='loopback'):
        campaign.LoopbackOnly().http_request(Request(url))
    with pytest.raises(ValueError, match='loopback'):
        campaign.NoExternalRedirect().redirect_request(
            Request('http://127.0.0.1:8123'), None, 302, '', {}, url)


def test_runner_allows_literal_loopback():
    request = Request('http://127.0.0.1:8123/qty-promotions', data=b'name=Test')
    handler = campaign.LoopbackOnly()
    build_opener(handler)  # Attach urllib's normal opener context; no request is sent.
    assert handler.http_request(request) is request
    assert request.get_header('Content-type') == 'application/x-www-form-urlencoded'
