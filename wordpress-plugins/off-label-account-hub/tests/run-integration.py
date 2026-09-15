"""Runs only against the disposable fixture required by integration.php.
Set OLR_TEST_WP, OLR_TEST_PHP and OLR_TEST_PHP_INI. No production connections.
"""
from pathlib import Path
import concurrent.futures
import json
import os
import secrets
import subprocess
import time
import urllib.error
import urllib.request

tests = Path(__file__).resolve().parent
fixture = Path(os.environ['OLR_TEST_WP']).resolve()
root = fixture.parent
php = [os.environ['OLR_TEST_PHP'], '-c', os.environ['OLR_TEST_PHP_INI']]
env = os.environ.copy()
subprocess.run(php + [str(tests / 'integration.php')], env=env, check=True)
context = json.loads((root / 'context.json').read_text())
worker = str(tests / 'request-worker.php')

def invoke(mode):
    result = subprocess.run(php + [worker, mode], capture_output=True, env=env, check=True, timeout=60)
    return json.loads(result.stdout)

with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    futures = [pool.submit(invoke, 'concurrent') for _ in range(2)]
    results = [f.result() for f in futures]
assert all(r['ok'] and r['balance']['balance'] == context['baseline'] + 5000 for r in results), results
assert results[0]['id'] == results[1]['id'], results
print('PASS: concurrent independent PHP processes settle and credit exactly once')
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results = [f.result() for f in [pool.submit(invoke, 'activate') for _ in range(2)]]
assert all(r['ok'] and r['count'] == 1 for r in results) and results[0]['id'] == results[1]['id'], results
print('PASS: concurrent new-member activations create exactly one affiliate')
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results = [f.result() for f in [pool.submit(invoke, 'repair-code') for _ in range(2)]]
assert all(r['ok'] and r['code'] for r in results) and results[0]['code'] == results[1]['code'], results
assert invoke('code-csrf') == -1  # WordPress check_ajax_referer terminates before code creation.
print('PASS: concurrent code preparation reuses one coupon; code endpoint rejects invalid CSRF nonce')
for modes in [('payout-race', 'payout-race'), ('spend-0', 'spend-1')]:
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = [f.result() for f in [pool.submit(invoke, mode) for mode in modes]]
    assert sum(r['ok'] for r in results) == 1, results
print('PASS: different payout keys cannot double-settle; simultaneous orders cannot double-spend credit')
assert invoke('security')['guarded_routes'] == 5
assert invoke('csrf')['error'] is True
print('PASS: five direct vendor routes and invalid CSRF nonce are rejected')

public = root / 'http-fixture'
public.mkdir(exist_ok=True)
token = secrets.token_hex(32)
(root / 'http.token').write_text(token)
(public / 'router.php').write_text("<?php require '" + Path(worker).as_posix() + "';")
server = subprocess.Popen(php + ['-S', '127.0.0.1:8339', '-t', str(public), str(public / 'router.php')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def request(mode, data=None, extra=None):
    headers = {'X-OLR-QA': token}
    headers.update(extra or {})
    req = urllib.request.Request('http://127.0.0.1:8339/worker.php?mode=' + mode, data=data, headers=headers)
    try:
        response = urllib.request.urlopen(req, timeout=30)
        return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()

pdf = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'

def upload(content, filename='w9.pdf', signed='1'):
    boundary = 'OLR-QA-BOUNDARY'
    data = (f'--{boundary}\r\nContent-Disposition: form-data; name="signed"\r\n\r\n{signed}\r\n--{boundary}\r\nContent-Disposition: form-data; name="w9"; filename="{filename}"\r\nContent-Type: application/pdf\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode())
    return json.loads(request('upload', data, {'Content-Type': 'multipart/form-data; boundary=' + boundary})[2])

try:
    for attempt in range(30):
        try:
            request('security')
            break
        except urllib.error.URLError:
            time.sleep(.1)
    for content, filename, signed in [(b'not a PDF', 'w9.pdf', '1'), (pdf, 'w9.php', '1'), (pdf, 'w9.pdf', '0'), (pdf.replace(b'/Catalog', b'/Catalog /JavaScript'), 'w9.pdf', '1'), (pdf + b'0' * (10 * 1024 * 1024), 'w9.pdf', '1')]:
        assert upload(content, filename, signed)['ok'] is False
    print('PASS: multipart uploads reject disguised files, wrong extensions, missing signature attestation, active content and oversized files')
    assert upload(pdf)['ok'] is True
    assert request('download&admin=0')[0] == 403
    code, headers, content = request('download&admin=1')
    assert code == 200 and content == pdf
    assert 'no-cache' in headers['Cache-Control'] and 'attachment' in headers['Content-Disposition']
    encrypted = max((root / 'private').glob('*.bin'), key=lambda p: p.stat().st_mtime_ns)
    original = encrypted.read_bytes()
    assert not original.startswith(b'%PDF')
    encrypted.write_bytes(original[:-1] + bytes([original[-1] ^ 1]))
    assert request('download&admin=1')[0] == 404
    encrypted.write_bytes(original)
    print('PASS: valid W-9 encrypted; member download denied; admin receives original PDF; tampering fails closed')
finally:
    server.terminate()
    server.wait(timeout=10)
print('Account Hub integration and request tests passed.')
