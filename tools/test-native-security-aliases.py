#!/usr/bin/env python3
"""Validate native security alias and download helpers without platform/network access."""
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

spec = importlib.util.spec_from_file_location('native_security', Path(__file__).with_name('native-security.py'))
security = importlib.util.module_from_spec(spec)
spec.loader.exec_module(security)


class NativeSourceAliases(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='lunixi-native-alias-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'native'
        self.source = self.root / 'app/code/Magento/Customer'
        self.source.mkdir(parents=True)
        (self.source / 'fixture.txt').write_text('native source')
        self.target = self.root / 'vendor/magento/module-customer'

    def ensure(self):
        security.ensure_source_alias(self.root, self.target, self.source)

    def test_new_alias_is_relative_and_points_to_exact_source(self):
        self.ensure()
        self.assertFalse(os.path.isabs(os.readlink(self.target)))
        self.assertEqual(self.target.resolve(), self.source.resolve())

    def test_repeated_alias_is_idempotent(self):
        self.ensure()
        original = os.readlink(self.target)
        self.ensure()
        self.assertEqual(os.readlink(self.target), original)

    def test_known_absolute_alias_is_normalized(self):
        self.target.parent.mkdir(parents=True)
        self.target.symlink_to(self.source.resolve(), target_is_directory=True)
        self.ensure()
        self.assertFalse(os.path.isabs(os.readlink(self.target)))
        self.assertEqual(self.target.resolve(), self.source.resolve())

    def test_foreign_alias_is_rejected_without_replacing_it(self):
        foreign = self.root / 'other'
        foreign.mkdir()
        self.target.parent.mkdir(parents=True)
        self.target.symlink_to(foreign.resolve(), target_is_directory=True)
        with self.assertRaises(RuntimeError):
            self.ensure()
        self.assertEqual(self.target.resolve(), foreign.resolve())

    def test_source_outside_test_root_is_rejected(self):
        outside = self.base / 'outside'
        outside.mkdir()
        with self.assertRaises(RuntimeError):
            security.ensure_source_alias(self.root, self.target, outside)
        self.assertFalse(self.target.exists())

    def test_vendor_parent_outside_test_root_is_rejected(self):
        outside = self.base / 'outside'
        outside.mkdir()
        (self.root / 'vendor').symlink_to(outside, target_is_directory=True)
        with self.assertRaises(RuntimeError):
            self.ensure()
        self.assertEqual(list(outside.iterdir()), [])

    def test_installed_real_vendor_directory_is_preserved(self):
        self.target.mkdir(parents=True)
        marker = self.target / 'installed.txt'
        marker.write_text('existing vendor package')
        self.ensure()
        self.assertFalse(self.target.is_symlink())
        self.assertEqual(marker.read_text(), 'existing vendor package')


class NativePatchDownloads(unittest.TestCase):
    name = 'fixture-CE.zip'
    url = 'https://repo.magento.com/patch/' + name

    def error(self, status, retry_after=None):
        headers = {} if retry_after is None else {'Retry-After': retry_after}
        return urllib.error.HTTPError(self.url, status, 'synthetic response', headers, None)

    def test_retry_after_seconds_is_respected_before_retry(self):
        with patch.object(security.urllib.request, 'urlopen', side_effect=[self.error(429, '3'), io.BytesIO(b'archive')]) as request, \
                patch.object(security.time, 'sleep') as sleep:
            self.assertEqual(security.download_patch(self.name), b'archive')
        sleep.assert_called_once_with(3)
        self.assertEqual(request.call_count, 2)
        self.assertEqual(request.call_args.args[0].full_url, self.url)

    def test_retry_after_http_date_is_respected(self):
        now = datetime(2026, 11, 1, 20, 0, tzinfo=timezone.utc)
        after = format_datetime(now + timedelta(seconds=12), usegmt=True)
        with patch.object(security.urllib.request, 'urlopen', side_effect=[self.error(503, after), io.BytesIO(b'archive')]), \
                patch.object(security.time, 'sleep') as sleep, patch.object(security, 'datetime') as clock:
            clock.now.return_value = now
            self.assertEqual(security.download_patch(self.name), b'archive')
        sleep.assert_called_once_with(12)

    def test_transient_network_and_server_failures_back_off(self):
        failures = [urllib.error.URLError('synthetic connection'), TimeoutError(), self.error(502), io.BytesIO(b'archive')]
        with patch.object(security.urllib.request, 'urlopen', side_effect=failures) as request, \
                patch.object(security.time, 'sleep') as sleep:
            self.assertEqual(security.download_patch(self.name), b'archive')
        self.assertEqual(request.call_count, 4)
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [1, 2, 4])

    def test_attempts_are_bounded_and_last_failure_is_preserved(self):
        failure = self.error(429)
        with patch.object(security.urllib.request, 'urlopen', side_effect=failure) as request, \
                patch.object(security.time, 'sleep') as sleep:
            with self.assertRaises(urllib.error.HTTPError) as raised:
                security.download_patch(self.name)
        self.assertIs(raised.exception, failure)
        self.assertEqual(request.call_count, security.DOWNLOAD_ATTEMPTS)
        self.assertEqual(sleep.call_count, security.DOWNLOAD_ATTEMPTS - 1)

    def test_nontransient_error_does_not_retry(self):
        with patch.object(security.urllib.request, 'urlopen', side_effect=self.error(404)) as request, \
                patch.object(security.time, 'sleep') as sleep:
            with self.assertRaises(urllib.error.HTTPError):
                security.download_patch(self.name)
        self.assertEqual(request.call_count, 1)
        sleep.assert_not_called()

    def test_long_retry_after_fails_without_early_retry_or_unbounded_wait(self):
        with patch.object(security.urllib.request, 'urlopen', side_effect=self.error(429, '3600')) as request, \
                patch.object(security.time, 'sleep') as sleep:
            with self.assertRaises(urllib.error.HTTPError):
                security.download_patch(self.name)
        self.assertEqual(request.call_count, 1)
        sleep.assert_not_called()

    def test_invalid_retry_after_uses_bounded_backoff(self):
        with patch.object(security.urllib.request, 'urlopen', side_effect=[self.error(429, 'invalid'), io.BytesIO(b'archive')]), \
                patch.object(security.time, 'sleep') as sleep:
            self.assertEqual(security.download_patch(self.name), b'archive')
        sleep.assert_called_once_with(1)

    def test_successful_download_still_requires_pinned_checksum(self):
        with tempfile.TemporaryDirectory(prefix='lunixi-native-download-') as directory:
            root = Path(directory)
            native = root / 'native'
            native.mkdir()
            (native / 'composer.json').write_text(json.dumps({'version': '2.4.9'}))
            with patch.object(security, '__file__', str(root / 'tools/native-security.py')), \
                    patch.object(security, 'PATCHES', [(self.name, '0' * 64, '-CE.patch')]), \
                    patch.object(security.sys, 'argv', ['native-security.py', str(native)]), \
                    patch.dict(os.environ, {'LUNIXI_NATIVE_TEST_DESTRUCTIVE': '1'}), \
                    patch.object(security, 'download_patch', return_value=b'tampered archive'), \
                    patch.object(security.subprocess, 'run') as apply_patch:
                with self.assertRaisesRegex(RuntimeError, 'checksum mismatch'):
                    security.main()
                apply_patch.assert_not_called()


if __name__ == '__main__':
    unittest.main(verbosity=2)
