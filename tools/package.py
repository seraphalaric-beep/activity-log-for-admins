"""Build an installable archive with stable paths, permissions and timestamps."""

import json
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
SLUG = "activity-log-for-admins"
PLUGIN = ROOT / SLUG
version = re.search(r"^ \* Version: (\S+)$", (PLUGIN / f"{SLUG}.php").read_text(), re.M)[1]
manifest = json.loads((ROOT / "package.json").read_text())
assert version == manifest["version"]
assert f"Stable tag: {version}" in (PLUGIN / "readme.txt").read_text()
release = ROOT / "release"
release.mkdir(exist_ok=True)
entries = {f"{SLUG}/{p.relative_to(PLUGIN).as_posix()}": p for p in PLUGIN.rglob("*") if p.is_file()}
for p in (ROOT / "ui").rglob("*"):
    if p.is_file():
        entries[f"{SLUG}/source/{p.relative_to(ROOT).as_posix()}"] = p
for name in ("package.json", "package-lock.json", "vite.config.js"):
    entries[f"{SLUG}/source/{name}"] = ROOT / name

lock = json.loads((ROOT / "package-lock.json").read_text())["packages"]
seen = set()
pending = list(manifest["dependencies"])
while pending:
    name = pending.pop()
    if name in seen:
        continue
    seen.add(name)
    package = ROOT / "node_modules" / name
    for p in package.iterdir():
        if p.is_file() and p.name.lower().startswith(("license", "notice", "copying")):
            entries[f"{SLUG}/licenses/{name}/{p.name}"] = p
    pending.extend(lock[f"node_modules/{name}"].get("dependencies", {}))
for asset in (
    "admin.js", "admin.css", "dashboard.css", "theme.css",
    "fonts/manrope-latin.woff2", "fonts/manrope-latin-ext.woff2", "fonts/Manrope-OFL.txt",
):
    assert f"{SLUG}/build/{asset}" in entries, f"Run the frontend build to create {asset}."

archive = release / f"{SLUG}.{version}.zip"
with ZipFile(archive, "w", compression=ZIP_DEFLATED, compresslevel=9) as output:
    for name, source in sorted(entries.items()):
        info = ZipInfo(name, (1980, 1, 1, 0, 0, 0))
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        output.writestr(info, source.read_bytes(), ZIP_DEFLATED, 9)
print(archive)
