#!/usr/bin/env python3
"""Package an explicitly prepared production directory, never the whole repository."""
import argparse
import hashlib
import json
import re
import stat
import zipfile
from pathlib import Path


def package(source, output, release_id, version=None):
    source, output = Path(source).resolve(), Path(output).resolve()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{0,79}", release_id):
        raise ValueError("Release-ID: 1–80 letters, digits, underscores or hyphens")
    if version is not None and (not isinstance(version, str) or
                                not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._+-]{0,127}", version)):
        raise ValueError("Version: 1–128 letters, digits, dots, underscores, pluses or hyphens")
    if not (source / "public").is_dir():
        raise ValueError("Production directory must contain public/")
    if output == source or source in output.parents:
        raise ValueError("Write the ZIP outside the production directory")
    files = []
    for path in sorted(source.rglob("*")):
        relative = path.relative_to(source)
        name = relative.as_posix()
        if path.is_symlink():
            raise ValueError(f"Symlinks must be configured as shared paths: {name}")
        if any(p in {".git", ".svn", ".ssh", "node_modules"} or
               (p.startswith(".env") and p != ".env.example") for p in relative.parts):
            raise ValueError(f"Forbidden production content: {name}")
        if relative.parts[0] in {"release.json", ".deploy-ready.json", "deployment.json"}:
            raise ValueError(f"Reserved filename: {name}")
        if "\\" in name or ":" in name or any(ord(c) < 32 for c in name):
            raise ValueError(f"Unsupported path: {name!r}")
        if path.is_file():
            files.append((path, name))
        elif not path.is_dir():
            raise ValueError(f"Not a regular file: {name}")
    output.parent.mkdir(parents=True, exist_ok=True)
    # Exclusive creation prevents overwriting an existing release artifact.
    with zipfile.ZipFile(output, "x", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("release.json", json.dumps({"format": 1, "id": release_id, "version": version}))
        for path, name in files:
            info = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            mode = 0o755 if path.stat().st_mode & 0o111 else 0o644
            info.external_attr = (stat.S_IFREG | mode) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            with path.open("rb") as src, archive.open(info, "w") as dest:
                while chunk := src.read(1024 * 1024):
                    dest.write(chunk)
    digest = hashlib.sha256()
    with output.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            digest.update(chunk)
    Path(str(output) + ".sha256").write_text(digest.hexdigest() + "\n")
    print(output)
    print(digest.hexdigest())


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", help="Prepared production tree containing public/")
    parser.add_argument("output", help="New release ZIP, outside source")
    parser.add_argument("release_id", help="Unique ID, e.g. commit SHA plus CI run number")
    parser.add_argument("--version", help="Application version from the project manifest or published tag; omit if unknown")
    args = parser.parse_args()
    try:
        package(args.source, args.output, args.release_id, args.version)
    except (ValueError, OSError) as error:
        parser.exit(1, f"Packaging failed: {error}\n")
