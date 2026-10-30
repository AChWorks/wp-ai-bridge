#!/usr/bin/env python3
"""Dependency-free tests for the CI image metadata boundary; no Docker daemon."""
import copy
import hashlib
import gzip
import importlib.util
import io
import json
import pathlib
import tarfile
import tempfile
import unittest
import sys

sys.dont_write_bytecode = True

ROOT = pathlib.Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("node_image", ROOT / "bin/issue199-completion-image.py")
NODE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(NODE)


class MetadataTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.temporary.name)
        layer = io.BytesIO()
        with tarfile.open(fileobj=layer, mode="w") as archive:
            info = tarfile.TarInfo("var/www/html/preserved.txt")
            payload = b"exact filesystem bytes\x00\xff"
            info.size = len(payload)
            archive.addfile(info, io.BytesIO(payload))
        self.layer = layer.getvalue()
        self.config = {"architecture": "amd64", "os": "linux", "created": "2026-01-01T00:00:00Z",
                       "history": [{"created_by": "official build"}],
                       "config": {"Volumes": {"/var/www/html": {}, "/other-volume": {}},
                                  "Entrypoint": ["docker-entrypoint.sh"], "Cmd": ["apache2-foreground"],
                                  "WorkingDir": "/var/www/html", "User": "www-data",
                                  "Env": ["EXACT=unchanged"], "Labels": {"original": "preserved"}},
                       "rootfs": {"type": "layers", "diff_ids": ["sha256:" + hashlib.sha256(self.layer).hexdigest()]}}
        self.derived_ref = "wpai-admin13-node:" + "a" * 64

    def tearDown(self):
        self.temporary.cleanup()

    def save(self, config=None, modern=False, extras=None, duplicate=False, layer=None):
        config = self.config if config is None else config
        encoded = NODE.canonical(config)
        source_id = "sha256:" + hashlib.sha256(encoded).hexdigest()
        config_name = "blobs/sha256/" + source_id[7:] if modern else source_id[7:] + ".json"
        layer_name = "blobs/sha256/" + "b" * 64 if modern else "layer/layer.tar"
        members = {"manifest.json": NODE.canonical([{"Config": config_name, "RepoTags": ["official:tag"],
                                                   "Layers": [layer_name]}]),
                   config_name: encoded, layer_name: self.layer if layer is None else layer}
        if modern:
            members.update({"index.json": b'{"stale":"must not survive"}', "oci-layout": b'{"imageLayoutVersion":"1.0.0"}'})
        members.update(extras or {})
        source = self.root / "source.tar"
        with tarfile.open(source, "w") as archive:
            for name, data in members.items():
                info = tarfile.TarInfo(name)
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
            if duplicate:
                info = tarfile.TarInfo("manifest.json")
                info.size = 2
                archive.addfile(info, io.BytesIO(b"[]"))
        return source, source_id, layer_name

    def transformed(self, modern=False):
        source, source_id, layer_name = self.save(modern=modern)
        output = self.root / "output.tar"
        derived_id = NODE.transform(source, output, self.derived_ref, source_id)
        with tarfile.open(output) as archive:
            manifest = json.load(archive.extractfile("manifest.json"))
            modified = json.load(archive.extractfile(manifest[0]["Config"]))
            self.assertEqual(archive.extractfile(layer_name).read(), self.layer)
            self.assertEqual(set(archive.getnames()), {"manifest.json", manifest[0]["Config"], layer_name})
            self.assertEqual(manifest[0]["RepoTags"], [self.derived_ref])
            self.assertEqual(manifest[0]["Config"], derived_id[7:] + ".json")
        expected = copy.deepcopy(self.config)
        del expected["config"]["Volumes"]["/var/www/html"]
        self.assertEqual(modified, expected)
        self.assertIn("/var/www/html", self.config["config"]["Volumes"])
        return derived_id

    def test_legacy_exact_single_semantic_change_and_layers(self):
        first = self.transformed()
        self.assertEqual(first, self.transformed())

    def test_modern_docker_save_drops_stale_oci_selection(self):
        self.transformed(modern=True)

    def test_modern_compressed_layer_keeps_exact_rootfs_bytes(self):
        source, source_id, layer_name = self.save(modern=True, layer=gzip.compress(self.layer, mtime=0))
        output = self.root / "output.tar"
        NODE.transform(source, output, self.derived_ref, source_id)
        with tarfile.open(output) as archive:
            self.assertEqual(archive.extractfile(layer_name).read(), self.layer)
            self.assertNotIn("index.json", archive.getnames())

    def test_empty_volume_inspection_normalization(self):
        source = {"Config": {"Volumes": {"/var/www/html": {}}, "User": "33"},
                  "RootFS": {"Type": "layers", "Layers": ["unchanged"]}}
        for volumes in (None, {}):
            derived = copy.deepcopy(source)
            derived["Config"]["Volumes"] = volumes
            derived["Id"] = "sha256:" + "c" * 64
            NODE.verify_inspection(source, derived)

    def test_reject_wrong_source_digest(self):
        source, _, _ = self.save()
        with self.assertRaisesRegex(ValueError, "digest"):
            NODE.transform(source, self.root / "out.tar", self.derived_ref, "sha256:" + "0" * 64)

    def test_reject_layer_corruption(self):
        source, source_id, _ = self.save(layer=b"corrupt")
        with self.assertRaisesRegex(ValueError, "layer bytes"):
            NODE.transform(source, self.root / "out.tar", self.derived_ref, source_id)

    def test_reject_duplicate_member(self):
        source, source_id, _ = self.save(duplicate=True)
        with self.assertRaisesRegex(ValueError, "duplicate"):
            NODE.transform(source, self.root / "out.tar", self.derived_ref, source_id)

    def test_reject_missing_or_unexpected_volume(self):
        for volume in (None, {}, {"/var/www/html": {"unexpected": True}}):
            config = copy.deepcopy(self.config)
            config["config"]["Volumes"] = volume
            with self.subTest(volume=volume), self.assertRaises(ValueError):
                NODE.changed_config(config)

    def test_reject_pure_oci_explicitly(self):
        path = self.root / "source.tar"
        with tarfile.open(path, "w") as archive:
            archive.addfile(tarfile.TarInfo("index.json"), io.BytesIO())
        with self.assertRaisesRegex(ValueError, "pure OCI"):
            NODE.transform(path, self.root / "out.tar", self.derived_ref, "sha256:" + "0" * 64)

    def test_path_admission(self):
        for name in ("/absolute", "../escape", "x/../escape", "x\\y", "x//y", "x/\0y"):
            with self.subTest(name=name), self.assertRaises(ValueError):
                NODE.safe_name(name)

    def test_no_source_overwrite(self):
        source, source_id, _ = self.save()
        with self.assertRaisesRegex(ValueError, "overwritten"):
            NODE.transform(source, source, self.derived_ref, source_id)

    def test_inspection_rejects_unrelated_changes(self):
        source = {"Config": self.config["config"], "RootFS": self.config["rootfs"],
                  "Architecture": "amd64", "Os": "linux", "Created": "2026-01-01"}
        derived = copy.deepcopy(source)
        del derived["Config"]["Volumes"]["/var/www/html"]
        derived["Id"] = "sha256:" + "c" * 64
        NODE.verify_inspection(source, derived)
        for key in ("Entrypoint", "WorkingDir", "User", "Env", "Labels"):
            mutant = copy.deepcopy(derived)
            mutant["Config"][key] = "changed"
            with self.subTest(key=key), self.assertRaises(ValueError):
                NODE.verify_inspection(source, mutant)
        for key in ("RootFS", "Architecture", "Os", "Created", "Id"):
            mutant = copy.deepcopy(derived)
            mutant[key] = "changed"
            with self.subTest(key=key), self.assertRaises(ValueError):
                NODE.verify_inspection(source, mutant)

    def test_compose_rejects_child_or_different_mounts(self):
        mount = {"type": "volume", "source": "wordpress-data", "target": "/var/www"}
        service = {"image": self.derived_ref, "pull_policy": "never", "volumes": [mount]}
        document = {"services": {"wordpress": copy.deepcopy(service), "cli": copy.deepcopy(service)}}
        path = self.root / "compose.json"
        path.write_text(json.dumps(document))
        NODE.check_compose(path)
        for policy in (None, "always", "missing"):
            mutant = copy.deepcopy(document)
            mutant["services"]["cli"]["pull_policy"] = policy
            path.write_text(json.dumps(mutant))
            with self.subTest(policy=policy), self.assertRaisesRegex(ValueError, "never be pulled"):
                NODE.check_compose(path)
        document["services"]["cli"]["volumes"].append({"type": "volume", "target": "/var/www/html"})
        path.write_text(json.dumps(document))
        with self.assertRaises(ValueError):
            NODE.check_compose(path)


if __name__ == "__main__":
    unittest.main()
