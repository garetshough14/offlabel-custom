"""Package only the distributable Phone Popup plugin, with a single directory level."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[1]
source = root / "wordpress-plugins/off-label-phone-popup"
output = root / "wordpress-plugins/_deploy/off-label-phone-popup-v1.0.1.zip"
output.parent.mkdir(parents=True, exist_ok=True)
files = [source / "off-label-phone-popup.php", source / "README.md", source / "QA.md"]
for folder in ("assets", "includes", "templates"):
    files.extend(sorted((source / folder).glob("*")))
with ZipFile(output, "w", ZIP_DEFLATED) as archive:
    for file in files:
        if file.is_file():
            archive.write(file, "off-label-phone-popup/" + str(file.relative_to(source)))
with ZipFile(output) as archive:
    assert archive.testzip() is None
    assert "off-label-phone-popup/off-label-phone-popup.php" in archive.namelist()
    assert not any("tests/" in name or name.endswith(".csv") for name in archive.namelist())
print(output)
print(f"{len(files)} files; {output.stat().st_size:,} bytes; ZIP integrity passed")
