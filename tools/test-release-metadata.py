#!/usr/bin/env python3
"""Regress public Composer exports through the actual builder without dependency/network I/O."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
manifest = json.loads((SOURCE / 'composer.json').read_text())
version = (SOURCE / 'VERSION').read_text().strip()
cases = [('stripped', None, version, True), ('matching', version, version, True),
         ('conflicting', '999.0.0', version, False), ('null', None, version, False),
         ('missing-file', None, None, False), ('unsafe-file', None, '../invalid', False)]
for name, composer_version, file_version, accepted in cases:
    with tempfile.TemporaryDirectory(prefix='lunixi-magento-release-') as temporary:
        root = Path(temporary)
        (root / 'tools').mkdir()
        shutil.copyfile(SOURCE / 'tools/build.php', root / 'tools/build.php')
        copied = dict(manifest)
        copied.pop('version', None)
        if composer_version is not None or name == 'null':
            copied['version'] = composer_version
        (root / 'composer.json').write_text(json.dumps(copied))
        if file_version is not None:
            (root / 'VERSION').write_text(file_version + '\n')
        result = subprocess.run([PHP, str(root / 'tools/build.php'), '--check-release'],
                                capture_output=True, text=True)
        if (result.returncode == 0) != accepted:
            raise RuntimeError('Actual builder release validation failed: ' + name)
        if accepted and result.stdout.strip() != version:
            raise RuntimeError('Actual builder reported a different release: ' + name)
        if any((root / path).exists() for path in ['build', 'sdk', 'dist']):
            raise RuntimeError('Release validation unexpectedly built dependencies: ' + name)
        print('PASS actual builder release metadata: ' + name)
print('6 release metadata regression cases passed; no network/dependency fetch was needed.')
