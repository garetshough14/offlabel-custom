"""Build only the Account Hub release. Never bundle vendor code or QA fixtures."""
from pathlib import Path
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
source = root / 'wordpress-plugins' / 'off-label-account-hub'
main = (source / 'off-label-account-hub.php').read_text(encoding='utf-8')
version = re.search(r'Version:\s*([\d.]+)', main).group(1)
assert "const VERSION" in main and "'" + version + "'" in main
output = root / 'wordpress-plugins' / '_deploy'
output.mkdir(exist_ok=True)
target = output / f'off-label-account-hub-v{version}.zip'
files = []
for path in sorted(source.rglob('*')):
    if not path.is_file() or path.is_symlink():
        continue
    relative = path.relative_to(source)
    if any(part.startswith('.') or part in ('tests', '__pycache__') for part in relative.parts):
        continue
    if path.suffix.lower() not in ('.php', '.css', '.js', '.svg', '.png', '.jpg', '.webp', '.woff', '.woff2', '.ttf', '.txt', '.md'):
        raise RuntimeError(f'Unexpected release file: {relative}')
    files.append((path, 'off-label-account-hub/' + relative.as_posix()))
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
    for path, name in files:
        bundle.write(path, name)
with zipfile.ZipFile(target) as bundle:
    assert bundle.testzip() is None
    assert bundle.namelist() == [name for _, name in files]
    for required in ('off-label-account-hub.php', 'includes/class-olr-affiliate-service.php',
                     'includes/class-olr-affiliate-coupons.php',
                     'includes/class-olr-affiliate-flows.php', 'includes/class-olr-store-credit.php',
                     'includes/class-olr-order-tracking.php', 'templates/member-dashboard.php',
                     'assets/account-hub.css', 'assets/store-credit.css', 'assets/fonts/BebasNeue-Regular.ttf', 'assets/fonts/OFL.txt', 'README.md'):
        assert 'off-label-account-hub/' + required in bundle.namelist()
    for path, name in files:
        assert bundle.read(name) == path.read_bytes()
digest = hashlib.sha256(target.read_bytes()).hexdigest()
target.with_suffix('.zip.sha256').write_text(f'{digest}  {target.name}\n', encoding='ascii')
print(f'Packaged {len(files)} verified files: {target}')
print(f'SHA-256: {digest}')
