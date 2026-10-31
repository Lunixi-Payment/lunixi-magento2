#!/usr/bin/env python3
"""Validate the actual native security alias helper without platform/network access."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

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


if __name__ == '__main__':
    unittest.main(verbosity=2)
