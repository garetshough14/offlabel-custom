"""Verify the PHP S3 signing request independently with AWS's botocore signer; fake data only."""
import json
import os
from pathlib import Path
import subprocess
import sys
sys.path.insert(0, str(Path(os.environ['TEMP']) / 'olr-aws-signing-qa'))
from botocore.auth import S3SigV4Auth
from botocore.awsrequest import AWSRequest
from botocore.credentials import Credentials

root = Path(__file__).resolve().parents[1]
php = Path(os.environ['TEMP']) / 'olr-presentation-qa-1013/php/php.exe'
source = root / 'wordpress-plugins/off-label-account-hub/tests/taxbandits-signature.php'
request = json.loads(subprocess.check_output([str(php), str(source)], text=True))
headers = request['args']['headers'].copy()
actual = headers.pop('Authorization')
req = AWSRequest(method='GET', url=request['url'], headers=headers)
req.context['timestamp'] = headers['x-amz-date']
signer = S3SigV4Auth(Credentials('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'), 's3', 'us-east-1')
signature = signer.signature(signer.string_to_sign(req, signer.canonical_request(req)), req)
assert actual.endswith('Signature=' + signature), 'PHP signing differs from AWS botocore'
out = root / 'output/account-hub-1.2.7'
out.mkdir(parents=True, exist_ok=True)
(out / 'signature-check.txt').write_text('PASS: PHP SSE-C GetObject signature matches AWS botocore using fixed synthetic credentials and timestamp.\n')
print('PASS: PHP SSE-C GetObject signature matches AWS botocore.')
