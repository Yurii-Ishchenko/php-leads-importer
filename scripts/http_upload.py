#!/usr/bin/env python3
"""Upload an XLSX through the public Laravel form with session cookies and CSRF."""
import argparse
import http.cookiejar
import json
from pathlib import Path
import re
import secrets
import time
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def upload(url, path, wait=False):
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect()
    )
    with opener.open(url, timeout=30) as response:
        page = response.read().decode()
        assert response.status == 200
    token = re.search(r'name="_token" value="([^"]+)"', page).group(1)
    boundary = '----leads-' + secrets.token_hex(16)
    filename = Path(path).name.replace('"', '').replace('\r', '').replace('\n', '')
    prefix = (
        f'--{boundary}\r\nContent-Disposition: form-data; name="_token"\r\n\r\n{token}\r\n'
        f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'
    ).encode()
    request = urllib.request.Request(
        urllib.parse.urljoin(url, '/imports'),
        data=prefix + Path(path).read_bytes() + f'\r\n--{boundary}--\r\n'.encode(),
        headers={'Content-Type': f'multipart/form-data; boundary={boundary}', 'Referer': url, 'Accept': 'application/json'},
    )
    start = time.monotonic()
    with opener.open(request, timeout=60) as response:
        assert response.status == 202, f'Upload returned HTTP {response.status}'
        assert 'Location' not in response.headers
        data = json.load(response)
    report = {'upload_http_status': 202, 'import_id': data['id'],
              'initial_status': data['status'], 'status_url': data['status_url'],
              'upload_request_seconds': round(time.monotonic() - start, 3)}
    if wait:
        deadline = time.monotonic() + 240
        polls = 0
        while True:
            with opener.open(urllib.request.Request(data['status_url'], headers={'Accept': 'application/json'}), timeout=30) as response:
                assert response.status == 200
                data = json.load(response)
                polls += 1
            if data['status'] in ('completed', 'failed'):
                break
            if time.monotonic() > deadline:
                raise TimeoutError('Import did not reach a terminal status')
            time.sleep(1)
        report.update({'polls': polls, 'status': data['status'],
                       'processed_rows': data['processed_rows'], 'total_rows': data['total_rows'],
                       'duration_ms': data['duration_ms'], 'error': data['error']})
        assert data['status'] == 'completed', data['error']
    return report


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('xlsx')
    parser.add_argument('--url', default='http://localhost:8080/')
    parser.add_argument('--wait', action='store_true', help='Poll JSON status until the queue completes the import')
    args = parser.parse_args()
    print(json.dumps(upload(args.url, args.xlsx, args.wait), ensure_ascii=False, indent=2))
