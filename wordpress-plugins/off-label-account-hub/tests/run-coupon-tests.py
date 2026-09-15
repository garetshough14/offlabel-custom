"""Run coupon tests only against the disposable WP fixture used by integration.php."""
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess

tests = Path(__file__).resolve().parent
php = [os.environ['OLR_TEST_PHP'], '-c', os.environ['OLR_TEST_PHP_INI']]
subprocess.run(php + [str(tests / 'coupon-customization.php')], check=True)

def invoke(mode):
    result = subprocess.run(php + [str(tests / 'request-worker.php'), mode], capture_output=True, text=True, check=True, timeout=60)
    return json.loads(result.stdout)

with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results = list(pool.map(invoke, ['choose-race-0', 'choose-race-1']))
assert sum(result['ok'] for result in results) == 1, results
assert invoke('choose-csrf')['error'] is True
assert invoke('coupon-admin-denied')['ok'] is False
print('PASS: simultaneous members claiming one code have exactly one winner')
print('PASS: invalid member nonce and unauthorized administrator POST are refused')
