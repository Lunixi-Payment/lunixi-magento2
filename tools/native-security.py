#!/usr/bin/env python3
"""Apply checksum-pinned official CE security patches to the disposable native source fixture."""
import hashlib
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
import zipfile

PATCHES = [
    ('2-4-9-jul-2026.zip', '650e8e34e925653a1b3980ce053b9cb993dc2b22e72ee80b5557e03f36848984', '-CE.patch'),
    ('2-4-9-aug-2026.zip', '0f300d043b5f7f9558d2d1ffe13b91ec84b5766549adbaec1fc73278ff72b0e1', '-CE.patch'),
    ('VULN-39341-composer-patches.zip', '5f98d409e627c0ac77fb9cbff4bf139c17ad69550dc06411a4b4d0aeadd669e1', '_249.patch'),
    ('2-4-9-sep-2026.zip', '9fe17a9681d94deaaeb2ca9245e1e1bcd9fad9b3478fbcaaa8ab1a94ee4cd686', '-CE.patch'),
    ('2-4-9-oct-2026.zip', '1d995f5998a65dc46c76c31bb4cff799ebaf26121ca933ec18a708393b2f1d30', '-CE.patch'),
]

DOWNLOAD_ATTEMPTS = 4
MAX_RETRY_DELAY = 30


def download_patch(name):
    """Retry transient official-host failures without weakening archive checksums."""
    request = urllib.request.Request('https://repo.magento.com/patch/' + name,
                                     headers={'User-Agent': 'Lunixi-native-security-fixture/0.1.2'})
    for attempt in range(DOWNLOAD_ATTEMPTS):
        delay = 2 ** attempt
        try:
            with urllib.request.urlopen(request, timeout=60) as response:
                return response.read()
        except urllib.error.HTTPError as error:
            if error.code not in {408, 429, 500, 502, 503, 504} or attempt == DOWNLOAD_ATTEMPTS - 1:
                raise
            retry_after = error.headers.get('Retry-After')
            if retry_after:
                try:
                    if retry_after.isdigit():
                        requested_delay = int(retry_after)
                    else:
                        retry_at = parsedate_to_datetime(retry_after)
                        requested_delay = (retry_at - datetime.now(timezone.utc)).total_seconds()
                except (ValueError, TypeError, OverflowError):
                    requested_delay = 0
                # Do not retry earlier than the server requested, or wait without a bound.
                if requested_delay > MAX_RETRY_DELAY:
                    raise
                delay = max(delay, requested_delay)
        except (urllib.error.URLError, TimeoutError):
            if attempt == DOWNLOAD_ATTEMPTS - 1:
                raise
        time.sleep(delay)
    raise RuntimeError('Official patch download exhausted its attempt bound')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def ensure_source_alias(root, target, source):
    """Keep Composer-path aliases inside TEST source and relative for GNU patch 2.7.6."""
    root = root.resolve()
    source = source.resolve()
    target = target.parent.resolve() / target.name
    if not source.is_relative_to(root) or not target.parent.resolve().is_relative_to(root):
        raise RuntimeError('Native source alias would escape the TEST root')
    if target.is_symlink():
        if target.resolve() != source:
            raise RuntimeError('Unexpected existing native source alias: ' + target.name)
        # Ubuntu 24 GNU patch 2.7.6 rejects absolute aliases even when the target
        # exists inside this root. Repair only the known alias to this exact source.
        if os.path.isabs(os.readlink(target)):
            target.unlink()
            target.symlink_to(os.path.relpath(source, target.parent), target_is_directory=True)
    elif not target.exists():
        target.parent.mkdir(parents=True, exist_ok=True)
        target.symlink_to(os.path.relpath(source, target.parent), target_is_directory=True)


def main():
    if os.environ.get('LUNIXI_NATIVE_TEST_DESTRUCTIVE') != '1':
        raise RuntimeError('This tool is only for the explicitly enabled disposable native TEST fixture')
    root = Path(sys.argv[1]).resolve()
    if json.loads((root / 'composer.json').read_text()).get('version') != '2.4.9':
        raise RuntimeError('Expected official 2.4.9 native source')
    marker = root / '.lunixi-security-fixture.json'
    if marker.exists():
        saved = json.loads(marker.read_text())
        if saved['patches'] != [list(p) for p in PATCHES]:
            raise RuntimeError('Native patch inventory drift')
        for name, expected in saved['files'].items():
            path = root / name
            if not path.is_file() or digest(path) != expected:
                raise RuntimeError('Previously patched native fixture changed: ' + name)
        print('Verified all pinned native security patch files, July through October plus VULN-39341.')
        return
    # Official patches use Composer package paths; GitHub source replaces these
    # packages. Native path aliases retain the ORIGINAL patch bytes/hunks.
    for base in (root / 'app/code', root / 'lib/internal'):
        for manifest in base.rglob('composer.json'):
            package = json.loads(manifest.read_text()).get('name', '')
            if not re.fullmatch(r'magento/[a-z0-9-]+', package):
                continue
            target = root / 'vendor' / package
            ensure_source_alias(root, target, manifest.parent)
    cache = Path(__file__).resolve().parents[1] / '.cache/native-security'
    cache.mkdir(parents=True, exist_ok=True)
    touched = set()
    for name, expected, suffix in PATCHES:
        archive = cache / name
        if not archive.exists():
            archive.write_bytes(download_patch(name))
        if digest(archive) != expected:
            raise RuntimeError('Official security archive checksum mismatch: ' + name)
        with zipfile.ZipFile(archive) as source:
            files = [p for p in source.namelist() if p.endswith(suffix) and '__MACOSX' not in p]
            if len(files) != 1:
                raise RuntimeError('Unexpected security archive component layout')
            patch = source.read(files[0])
        for line in patch.decode().splitlines():
            if line.startswith('+++ b/'):
                name_in_root = line[6:].split('\t')[0]
                if not (root / name_in_root).resolve().is_relative_to(root):
                    raise RuntimeError('Unsafe official patch path')
                touched.add(name_in_root)
        for dry_run in (True, False):
            command = ['patch', '--batch', '--forward', '--fuzz=0', '-p1']
            if dry_run:
                command.append('--dry-run')
            result = subprocess.run(command, input=patch, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            if result.returncode:
                output = result.stdout.decode()
                print(output if len(output) <= 6000 else output[:3000] + '\n[patch output truncated]\n' + output[-3000:])
                raise RuntimeError('Official patch does not apply cleanly: ' + files[0])
        print('Applied original official security patch: ' + files[0])
    hashes = {name: digest(root / name) for name in sorted(touched)}
    marker.write_text(json.dumps({'patches': PATCHES, 'files': hashes}, indent=2) + '\n')
    print('Pinned native CE security fixture complete; third-party Composer audit remains separate.')


if __name__ == '__main__':
    main()
